<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\StatusPages\CreateStatusPage;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CreateStatusPageParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'no slug' => [['title' => 'T']],
            'no title' => [['slug' => 'status-x']],
            'reserved slug' => [['slug' => 'admin', 'title' => 'T']],
            'bad slug' => [['slug' => 'Bad_Slug', 'title' => 'T']],
            'http logo' => [['slug' => 'status-x', 'title' => 'T', 'logo_url' => 'http://example.com/a.png']],
            'ip domain' => [['slug' => 'status-x', 'title' => 'T', 'custom_domain' => '10.0.0.1']],
            'bad locale' => [['slug' => 'status-x', 'title' => 'T', 'locale' => 'de']],
            'bad color' => [['slug' => 'status-x', 'title' => 'T', 'accent_color' => 'red']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_create_action_matches_api_validation(array $payload): void
    {
        // Custom domains on, so the domain is validated rather than refused by the policy.
        config(['status.custom_domains.allowed' => ['*.example.com']]);
        $ws = Workspace::factory()->create();
        $this->actingAs(User::factory()->memberOf($ws, WorkspaceRole::Admin)->create());

        $response = $this->postJson("/api/v1/workspaces/{$ws->slug}/status-pages", $payload);
        $response->assertStatus(422);

        try {
            app(CreateStatusPage::class)->validate($ws, $payload);
            $this->fail('Validation should have failed.');
        } catch (ValidationException $e) {
            $apiErrors = $response->json('errors');
            $this->assertIsArray($apiErrors);
            $this->assertSame(array_keys($apiErrors), array_keys($e->errors()));
            $this->assertEquals($apiErrors, $e->errors());
        }
    }

    public function test_valid_payload_round_trips_through_handle_inside_one_transaction(): void
    {
        $ws = Workspace::factory()->create();
        $action = app(CreateStatusPage::class);

        $validated = $action->validate($ws, ['slug' => 'status-x', 'title' => ' Status ', 'custom_domain' => ' Status.Example.COM. ']);
        $joined = null;
        $page = $action->handle($ws, $validated, null, function (StatusPage $page) use (&$joined): void {
            $joined = $page->id;
        });

        $this->assertSame($page->id, $joined);
        $this->assertSame('Status', $page->title);
        $this->assertSame('status.example.com', $page->custom_domain);
        $audit = AuditEvent::where('action', 'status_page.created')->sole();
        $this->assertSame($ws->id, $audit->workspace_id);
        $this->assertSame('system', $audit->actor_type);
    }

    public function test_within_failure_rolls_the_page_back(): void
    {
        $ws = Workspace::factory()->create();
        $action = app(CreateStatusPage::class);
        $validated = $action->validate($ws, ['slug' => 'status-x', 'title' => 'T']);

        try {
            $action->handle($ws, $validated, null, function (): void {
                throw new \RuntimeException('stop');
            });
            $this->fail('The callback exception should propagate.');
        } catch (\RuntimeException) {
            $this->assertSame(0, StatusPage::count());
            $this->assertSame(0, AuditEvent::where('action', 'status_page.created')->count());
        }
    }

    public function test_custom_domain_needs_the_permission_for_a_user_actor_but_not_the_system(): void
    {
        config(['status.custom_domains.allowed' => ['*.example.com']]);
        $ws = Workspace::factory()->create();
        $editor = User::factory()->memberOf($ws, WorkspaceRole::Editor)->create();
        $admin = User::factory()->memberOf($ws, WorkspaceRole::Admin)->create();
        $payload = ['slug' => 'status-x', 'title' => 'T', 'custom_domain' => 'status.example.com'];
        $action = app(CreateStatusPage::class);

        $this->assertSame('status.example.com', $action->validate($ws, $payload)['custom_domain']);
        $this->assertSame('status.example.com', $action->validate($ws, $payload, $admin)['custom_domain']);
        $this->expectException(AuthorizationException::class);
        $action->validate($ws, $payload, $editor);
    }
}
