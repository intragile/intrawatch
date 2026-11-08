<?php

declare(strict_types=1);

namespace App\Actions\Maintenances;

use App\Actions\Concerns\RunsInWorkspace;
use App\Http\Requests\MaintenanceRequest;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Workspace;
use App\Monitoring\Engine\MaintenanceOracle;
use App\Status\Maintenance\CalendarMaintenanceOracle;
use App\Status\Maintenance\MaintenanceCalendarLoader;
use App\Status\Maintenance\MaintenanceState;
use App\Status\Maintenance\MaintenanceSync;
use App\Status\Pages\AffectedPages;
use App\Status\Pages\PageStamps;
use App\Support\Audit;
use App\Support\RequestInput;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates a maintenance with its monitor and status page targets. Validation is the one of
 * `POST /maintenances`: the same form request evaluates the input, so the API, the import and any other
 * caller accept and refuse the same definitions with the same error keys.
 *
 * @phpstan-type ValidatedMaintenance array{attributes: array<string, mixed>, monitor_ids: list<int>, status_page_ids: list<int>}
 */
class CreateMaintenance
{
    use RunsInWorkspace;

    private const CHUNK = 500;

    public function __construct(
        private readonly MaintenanceSync $sync,
        private readonly MaintenanceOracle $oracle,
        private readonly PageStamps $stamps,
    ) {}

    /**
     * Validates a create payload (the body of `POST /maintenances`) and returns the values `handle` takes.
     * With `$checkTargets` false the monitors and status pages need not exist yet.
     *
     * @param  array<string, mixed>  $input
     * @return ValidatedMaintenance
     *
     * @throws ValidationException
     */
    public function validate(Workspace $workspace, array $input, bool $checkTargets = true): array
    {
        return $this->inWorkspace($workspace, function () use ($input, $checkTargets): array {
            $request = MaintenanceRequest::create('/', 'POST', RequestInput::normalize($input));
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
     * @return ValidatedMaintenance
     */
    public static function validated(MaintenanceRequest $request): array
    {
        return [
            'attributes' => $request->maintenanceAttributes(),
            'monitor_ids' => $request->monitorIds() ?? [],
            'status_page_ids' => $request->statusPageIds() ?? [],
        ];
    }

    /**
     * Persists a validated maintenance with its targets and audit row, and makes its windows take effect.
     * `$within` runs inside the transaction after everything is written. A null actor records the caller of
     * the current request, or the system.
     *
     * @param  ValidatedMaintenance  $validated  The result of `validate`.
     * @param  (Closure(Maintenance): void)|null  $within
     */
    public function handle(Workspace $workspace, array $validated, ?User $actor, ?Closure $within = null): Maintenance
    {
        $monitorIds = $validated['monitor_ids'];
        $statusPageIds = $validated['status_page_ids'];

        try {
            [$maintenance, $result] = DB::transaction(function () use ($workspace, $validated, $actor, $within, $monitorIds, $statusPageIds): array {
                MaintenanceCalendarLoader::bumpRevision();

                $maintenance = $workspace->maintenances()->create($validated['attributes']);
                $maintenance->monitors()->sync($monitorIds);
                foreach (array_chunk($statusPageIds, self::CHUNK) as $chunk) {
                    DB::table('status_page_links')->insert(array_map(
                        static fn (int $id): array => ['status_page_id' => $id, 'linkable_type' => 'maintenance', 'linkable_id' => $maintenance->id],
                        $chunk,
                    ));
                }

                Audit::record('maintenance.created', $maintenance, [
                    'title' => [null, $maintenance->title],
                    'strategy' => [null, $maintenance->strategy->value],
                    'active' => [null, $maintenance->active],
                    'monitor_ids' => [[], $monitorIds],
                    'status_page_ids' => [[], $statusPageIds],
                ], $actor, $workspace);
                if ($within !== null) {
                    $within($maintenance);
                }

                return [$maintenance, $this->sync->applyChange(null, MaintenanceState::of($maintenance, $workspace->timezone, $monitorIds))];
            });
        } catch (Throwable $e) {
            // The oracle reloads inside the transaction, so a failed write drops the calendar again.
            if ($this->oracle instanceof CalendarMaintenanceOracle) {
                $this->oracle->invalidate();
            }
            throw $e;
        }
        $result->afterCommit();
        $this->oracle->warm();
        $this->stamps->bumpPage(...AffectedPages::forMaintenance($maintenance->id));

        return $maintenance;
    }
}
