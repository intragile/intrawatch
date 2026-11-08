<?php

declare(strict_types=1);

namespace App\Actions\Incidents;

use App\Actions\Concerns\RunsInWorkspace;
use App\Enums\IncidentStatus;
use App\Http\Requests\IncidentRequest;
use App\Models\Incident;
use App\Models\User;
use App\Models\Workspace;
use App\Status\Pages\AffectedPages;
use App\Status\Pages\PageStamps;
use App\Support\Audit;
use App\Support\RequestInput;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates an incident with its first timeline update. Validation is the one of `POST /incidents`: the same
 * form request evaluates the input, so the API, the import and any other caller accept and refuse the same
 * values with the same error keys.
 *
 * @phpstan-type ValidatedIncident array{attributes: array<string, mixed>, status: IncidentStatus, message: string, monitor_ids: list<int>, status_page_ids: list<int>, resolved_at?: CarbonImmutable|null}
 */
class CreateIncident
{
    use RunsInWorkspace;

    private const CHUNK = 500;

    public function __construct(private readonly PageStamps $stamps) {}

    /**
     * Validates a create payload (the body of `POST /incidents`) and returns the values `handle` takes. With
     * `$checkTargets` false the monitors and status pages need not exist yet.
     *
     * @param  array<string, mixed>  $input
     * @return ValidatedIncident
     *
     * @throws ValidationException
     */
    public function validate(Workspace $workspace, array $input, bool $checkTargets = true): array
    {
        return $this->inWorkspace($workspace, function () use ($input, $checkTargets): array {
            $request = IncidentRequest::create('/', 'POST', RequestInput::normalize($input));
            $request->setContainer(Container::getInstance())->setRedirector(app(Redirector::class));
            if (! $checkTargets) {
                $request->withoutOwnershipChecks();
            }
            $request->validateResolved();

            return self::validated($request);
        });
    }

    /**
     * The values of a validated create request, as `handle` takes them.
     *
     * @return ValidatedIncident
     */
    public static function validated(IncidentRequest $request): array
    {
        return [
            'attributes' => $request->incidentAttributes(),
            'status' => IncidentStatus::from($request->string('status')->toString()),
            'message' => $request->string('message')->toString(),
            'monitor_ids' => $request->monitorIds() ?? [],
            'status_page_ids' => $request->statusPageIds() ?? [],
        ];
    }

    /**
     * Persists a validated incident with its first update, links and audit row. A resolved incident ends at
     * `resolved_at` when given, now otherwise. `$within` runs inside the transaction after everything is
     * written. A null actor records the caller of the current request, or the system.
     *
     * @param  ValidatedIncident  $validated  The result of `validate`, optionally with `resolved_at`.
     * @param  (Closure(Incident): void)|null  $within
     */
    public function handle(Workspace $workspace, array $validated, ?User $actor, ?Closure $within = null): Incident
    {
        $status = $validated['status'];
        $monitorIds = $validated['monitor_ids'];
        $statusPageIds = $validated['status_page_ids'];
        $now = CarbonImmutable::now();

        $incident = DB::transaction(function () use ($workspace, $validated, $actor, $within, $status, $monitorIds, $statusPageIds, $now): Incident {
            $incident = $workspace->incidents()->create($validated['attributes'] + [
                'status' => $status,
                'started_at' => $now,
                'resolved_at' => $status->isOpen() ? null : ($validated['resolved_at'] ?? $now),
            ]);
            $incident->updates()->create(['status' => $status, 'message' => $validated['message']]);
            $incident->monitors()->sync($monitorIds);
            foreach (array_chunk($statusPageIds, self::CHUNK) as $chunk) {
                DB::table('status_page_links')->insert(array_map(
                    static fn (int $id): array => ['status_page_id' => $id, 'linkable_type' => 'incident', 'linkable_id' => $incident->id],
                    $chunk,
                ));
            }

            Audit::record('incident.created', $incident, [
                'title' => [null, $incident->title],
                'impact' => [null, $incident->impact->value],
                'status' => [null, $status->value],
                'monitor_ids' => [[], $monitorIds],
                'status_page_ids' => [[], $statusPageIds],
            ], $actor, $workspace);
            if ($within !== null) {
                $within($incident);
            }

            return $incident;
        });
        $this->stamps->bumpPage(...AffectedPages::forIncident($incident->id));

        return $incident;
    }
}
