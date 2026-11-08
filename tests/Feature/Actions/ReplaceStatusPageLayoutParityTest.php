<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\StatusPages\ReplaceStatusPageLayout;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ReplaceStatusPageLayoutParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'no sections' => [[]],
            'unnamed section' => [['sections' => [['items' => []]]]],
            'item without monitor' => [['sections' => [['name' => 'A', 'items' => [['display_name' => 'x']]]]]],
            'foreign monitor' => [['sections' => [['name' => 'A', 'items' => [['monitor_id' => 999999]]]]]],
            'too many sections' => [['sections' => array_fill(0, 51, ['name' => 'A', 'items' => []])]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_layout_action_matches_api_validation(array $payload): void
    {
        $ws = Workspace::factory()->create();
        $page = StatusPage::factory()->create(['workspace_id' => $ws->id]);
        $this->actingAs(User::factory()->memberOf($ws, WorkspaceRole::Editor)->create());

        $response = $this->putJson("/api/v1/workspaces/{$ws->slug}/status-pages/{$page->id}/layout", $payload);
        $response->assertStatus(422);

        try {
            app(ReplaceStatusPageLayout::class)->validate($ws, $payload);
            $this->fail('Validation should have failed.');
        } catch (ValidationException $e) {
            $apiErrors = $response->json('errors');
            $this->assertIsArray($apiErrors);
            $this->assertEquals($apiErrors, $e->errors());
        }
    }

    public function test_valid_layout_round_trips_and_replaces_the_previous_one(): void
    {
        $ws = Workspace::factory()->create();
        $page = StatusPage::factory()->create(['workspace_id' => $ws->id]);
        $monitor = Monitor::factory()->create(['workspace_id' => $ws->id]);
        $action = app(ReplaceStatusPageLayout::class);

        $layout = $action->validate($ws, ['sections' => [['name' => 'A', 'items' => [['monitor_id' => $monitor->id]]]]]);
        $action->handle($ws, $page, $layout, null);
        $action->handle($ws, $page, $action->validate($ws, ['sections' => [['name' => 'B']]]), null);

        $this->assertSame(['B'], DB::table('status_page_sections')->pluck('name')->all());
        $this->assertSame(0, DB::table('status_page_items')->count());
        $this->assertSame(2, AuditEvent::where('action', 'status_page.layout_replaced')->count());
    }

    public function test_monitor_check_can_be_skipped_for_monitors_that_do_not_exist_yet(): void
    {
        $ws = Workspace::factory()->create();
        $action = app(ReplaceStatusPageLayout::class);
        $payload = ['sections' => [['name' => 'A', 'items' => [['monitor_id' => -1], ['monitor_id' => -2]]]]];

        $layout = $action->validate($ws, $payload, checkMonitors: false);

        $this->assertCount(2, $layout[0]['items']);
        $this->expectException(ValidationException::class);
        $action->validate($ws, $payload);
    }
}
