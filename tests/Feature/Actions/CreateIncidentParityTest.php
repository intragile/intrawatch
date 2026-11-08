<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\Incidents\CreateIncident;
use App\Enums\IncidentStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CreateIncidentParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'empty' => [[]],
            'no message' => [['title' => 'T', 'impact' => 'minor', 'status' => 'investigating']],
            'bad impact' => [['title' => 'T', 'impact' => 'huge', 'status' => 'investigating', 'message' => 'm']],
            'bad status' => [['title' => 'T', 'impact' => 'minor', 'status' => 'gone', 'message' => 'm']],
            'future start' => [['title' => 'T', 'impact' => 'minor', 'status' => 'investigating', 'message' => 'm', 'started_at' => '2999-01-01T00:00:00Z']],
            'foreign page' => [['title' => 'T', 'impact' => 'minor', 'status' => 'investigating', 'message' => 'm', 'status_page_ids' => [999999]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_create_action_matches_api_validation(array $payload): void
    {
        $ws = Workspace::factory()->create();
        $this->actingAs(User::factory()->memberOf($ws, WorkspaceRole::Editor)->create());

        $response = $this->postJson("/api/v1/workspaces/{$ws->slug}/incidents", $payload);
        $response->assertStatus(422);

        try {
            app(CreateIncident::class)->validate($ws, $payload);
            $this->fail('Validation should have failed.');
        } catch (ValidationException $e) {
            $apiErrors = $response->json('errors');
            $this->assertIsArray($apiErrors);
            $this->assertSame(array_keys($apiErrors), array_keys($e->errors()));
            $this->assertEquals($apiErrors, $e->errors());
        }
    }

    public function test_valid_payload_round_trips_through_handle(): void
    {
        $ws = Workspace::factory()->create();
        $page = StatusPage::factory()->create(['workspace_id' => $ws->id]);
        $action = app(CreateIncident::class);

        $validated = $action->validate($ws, ['title' => 'T', 'impact' => 'minor', 'status' => 'resolved', 'message' => 'done', 'started_at' => '2026-01-01T10:00:00Z', 'status_page_ids' => [$page->id]]);
        $validated['resolved_at'] = CarbonImmutable::parse('2026-01-01T11:00:00Z');
        $incident = $action->handle($ws, $validated, null);

        $this->assertSame(IncidentStatus::Resolved, $incident->status);
        $this->assertSame('2026-01-01 10:00:00', $incident->started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-01 11:00:00', $incident->resolved_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('done', $incident->updates()->sole()->message);
        $this->assertSame(1, DB::table('status_page_links')->where('linkable_type', 'incident')->where('linkable_id', $incident->id)->count());
        $this->assertSame('system', AuditEvent::where('action', 'incident.created')->sole()->actor_type);
    }

    public function test_targets_can_be_left_unchecked_for_entities_that_do_not_exist_yet(): void
    {
        $ws = Workspace::factory()->create();
        $payload = ['title' => 'T', 'impact' => 'minor', 'status' => 'investigating', 'message' => 'm', 'status_page_ids' => [-1]];

        $validated = app(CreateIncident::class)->validate($ws, $payload, checkTargets: false);

        $this->assertSame([-1], $validated['status_page_ids']);
        $this->assertSame(0, Incident::count());
    }
}
