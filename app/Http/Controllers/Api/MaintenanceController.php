<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\MaintenanceRequest;
use App\Http\Requests\UpdateMaintenanceRequest;
use App\Http\Resources\MaintenanceResource;
use App\Models\Maintenance;
use App\Models\Workspace;
use App\Monitoring\Engine\MaintenanceOracle;
use App\Status\Maintenance\CalendarMaintenanceOracle;
use App\Status\Maintenance\MaintenanceCalendarLoader;
use App\Status\Maintenance\MaintenanceState;
use App\Status\Maintenance\MaintenanceSync;
use App\Status\Maintenance\SyncResult;
use App\Status\Pages\AffectedPages;
use App\Status\Pages\PageStamps;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class MaintenanceController extends Controller
{
    private const CHUNK = 500;

    private const STATES = ['active', 'upcoming', 'past', 'all'];

    public function __construct(
        private readonly MaintenanceSync $sync,
        private readonly MaintenanceOracle $oracle,
        private readonly PageStamps $stamps,
    ) {}

    /**
     * Lists the maintenances of the workspace.
     *
     * `state` narrows the list: `active` windows in progress, `upcoming` ones with a later window, `past` ones
     * that will not run again, `all` (default) everything. The state is computed from each schedule, so the
     * narrowed lists select the matching ids first and then paginate them.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $request->validate(['state' => ['sometimes', Rule::in(self::STATES)]]);
        $state = $request->string('state', 'all')->toString();
        $now = CarbonImmutable::now();

        $query = $workspace->maintenances()->with(['monitors:id', 'statusPages:id'])->orderBy('maintenances.id');
        if ($state !== 'all') {
            $ids = $this->idsInState($workspace, $state, $now);
            $query->where(function (Builder $q) use ($ids): void {
                $q->whereRaw('1 = 0');
                foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                    $q->orWhereIn('maintenances.id', $chunk);
                }
            });
        }

        return CursorPage::from($query, $request, fn (Model $m): array => MaintenanceResource::toArray($this->maintenance($m), $workspace->timezone, $now));
    }

    public function show(Workspace $workspace, Maintenance $maintenance): JsonResponse
    {
        return $this->respond($workspace, $maintenance->id);
    }

    public function store(MaintenanceRequest $request, Workspace $workspace): JsonResponse
    {
        $monitorIds = $request->monitorIds() ?? [];
        $statusPageIds = $request->statusPageIds() ?? [];
        $id = 0;

        $result = $this->write(function () use ($request, $workspace, $monitorIds, $statusPageIds, &$id): SyncResult {
            $maintenance = $workspace->maintenances()->create($request->maintenanceAttributes());
            $id = $maintenance->id;
            $maintenance->monitors()->sync($monitorIds);
            $this->syncStatusPages($maintenance, $statusPageIds);

            Audit::record('maintenance.created', $maintenance, [
                'title' => [null, $maintenance->title],
                'strategy' => [null, $maintenance->strategy->value],
                'active' => [null, $maintenance->active],
                'monitor_ids' => [[], $monitorIds],
                'status_page_ids' => [[], $statusPageIds],
            ]);

            return $this->sync->applyChange(null, MaintenanceState::of($maintenance, $workspace->timezone, $monitorIds));
        });
        $result->afterCommit();
        $this->oracle->warm();
        $this->stamps->bumpPage(...AffectedPages::forMaintenance($id));

        return $this->respond($workspace, $id, 201);
    }

    public function update(UpdateMaintenanceRequest $request, Workspace $workspace, Maintenance $maintenance): JsonResponse
    {
        $affected = [];
        $result = $this->write(function () use ($request, $workspace, $maintenance, &$affected): SyncResult {
            $affected = AffectedPages::forMaintenance($maintenance->id);
            $oldMonitorIds = $this->monitorIds($maintenance);
            $oldStatusPageIds = $this->statusPageIds($maintenance);
            $before = MaintenanceState::of($maintenance, $workspace->timezone, $oldMonitorIds);
            $old = $maintenance->only(array_keys($request->maintenanceAttributes()));

            $maintenance->fill($request->maintenanceAttributes());
            $diff = [];
            foreach ($maintenance->getDirty() as $field => $_) {
                $diff[$field] = [$this->plain($old[$field] ?? null), $this->plain($maintenance->getAttribute($field))];
            }

            $monitorIds = $request->monitorIds() ?? $oldMonitorIds;
            $statusPageIds = $request->statusPageIds() ?? $oldStatusPageIds;
            if ($monitorIds !== $oldMonitorIds) {
                $maintenance->monitors()->sync($monitorIds);
                $diff['monitor_ids'] = [$oldMonitorIds, $monitorIds];
            }
            if ($statusPageIds !== $oldStatusPageIds) {
                $this->syncStatusPages($maintenance, $statusPageIds);
                $diff['status_page_ids'] = [$oldStatusPageIds, $statusPageIds];
            }
            // The calendar's change stamp follows `updated_at`, so a target change alone must move it too.
            $maintenance->save();
            $maintenance->touch();

            if ($diff !== []) {
                Audit::record('maintenance.updated', $maintenance, $diff);
            }

            return $this->sync->applyChange($before, MaintenanceState::of($maintenance->refresh(), $workspace->timezone, $monitorIds));
        });
        $result->afterCommit();
        $this->oracle->warm();
        // An edit can remove what a page showed (a page unlinked, a title reworded), so no last good copy of
        // the earlier content may be served during an outage.
        $this->stamps->forgetRenderings(...$affected, ...AffectedPages::forMaintenance($maintenance->id));

        return $this->respond($workspace, $maintenance->id);
    }

    public function destroy(Workspace $workspace, Maintenance $maintenance): Response
    {
        $affected = [];
        $result = $this->write(function () use ($workspace, $maintenance, &$affected): SyncResult {
            $affected = AffectedPages::forMaintenance($maintenance->id);
            $before = MaintenanceState::of($maintenance, $workspace->timezone, $this->monitorIds($maintenance));
            $maintenance->monitors()->detach();
            $maintenance->delete();
            Audit::record('maintenance.deleted', $maintenance, ['title' => [$maintenance->title, null]]);

            return $this->sync->applyChange($before, null);
        });
        $result->afterCommit();
        $this->oracle->warm();
        $this->stamps->forgetRenderings(...$affected);

        return response()->noContent();
    }

    /**
     * Ends a one-off maintenance now. Recurring ones are switched off with `active: false` instead.
     *
     * @throws ValidationException when the maintenance is recurring, has not started yet or has already ended
     */
    public function end(Workspace $workspace, Maintenance $maintenance): JsonResponse
    {
        if ($maintenance->strategy->value !== 'once') {
            throw ValidationException::withMessages(['strategy' => ['Only a one-off maintenance can be ended; deactivate a recurring one with active: false.']]);
        }

        $result = $this->write(function () use ($workspace, $maintenance): SyncResult {
            $monitorIds = $this->monitorIds($maintenance);
            $before = MaintenanceState::of($maintenance, $workspace->timezone, $monitorIds);
            $now = CarbonImmutable::now();
            $old = $maintenance->ends_at;
            if ($maintenance->starts_at === null || $maintenance->starts_at->gt($now)) {
                throw ValidationException::withMessages(['strategy' => ['The maintenance has not started yet; delete it or deactivate it instead.']]);
            }
            if ($old !== null && $old->lte($now)) {
                throw ValidationException::withMessages(['strategy' => ['The maintenance has already ended.']]);
            }
            $maintenance->forceFill(['ends_at' => $now])->save();
            Audit::record('maintenance.ended', $maintenance, ['ends_at' => [$old?->toIso8601String(), $now->toIso8601String()]]);

            return $this->sync->applyChange($before, MaintenanceState::of($maintenance->refresh(), $workspace->timezone, $monitorIds));
        });
        $result->afterCommit();
        $this->oracle->warm();
        $this->stamps->bumpPage(...AffectedPages::forMaintenance($maintenance->id));

        return $this->respond($workspace, $maintenance->id);
    }

    /**
     * Runs a write in one transaction. The oracle reloads inside it, so a failed write drops the calendar again.
     *
     * @param  Closure(): SyncResult  $work
     */
    private function write(Closure $work): SyncResult
    {
        try {
            return DB::transaction(function () use ($work): SyncResult {
                MaintenanceCalendarLoader::bumpRevision();

                return $work();
            });
        } catch (Throwable $e) {
            if ($this->oracle instanceof CalendarMaintenanceOracle) {
                $this->oracle->invalidate();
            }
            throw $e;
        }
    }

    /**
     * Ids of the workspace's maintenances in the given state.
     *
     * @return list<int>
     */
    private function idsInState(Workspace $workspace, string $state, CarbonImmutable $now): array
    {
        $ids = [];
        $workspace->maintenances()->orderBy('maintenances.id')->chunkById(self::CHUNK, function ($maintenances) use ($workspace, $state, $now, &$ids): void {
            foreach ($maintenances as $maintenance) {
                $window = MaintenanceResource::window($maintenance, $workspace->timezone, $now);
                $matches = match ($state) {
                    'active' => $window !== null && $window->contains($now),
                    'upcoming' => $window !== null && ! $window->contains($now),
                    default => $window === null,
                };
                if ($matches) {
                    $ids[] = $maintenance->id;
                }
            }
        }, 'maintenances.id', 'id');

        return $ids;
    }

    private function respond(Workspace $workspace, int $id, int $status = 200): JsonResponse
    {
        $fresh = Maintenance::query()->with(['monitors:id', 'statusPages:id'])->findOrFail($id);

        return response()->json(['data' => MaintenanceResource::toArray($fresh, $workspace->timezone, CarbonImmutable::now())], $status);
    }

    /**
     * @return list<int>
     */
    private function monitorIds(Maintenance $maintenance): array
    {
        $ids = DB::table('maintenance_monitor')->where('maintenance_id', $maintenance->id)->pluck('monitor_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();
        sort($ids);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function statusPageIds(Maintenance $maintenance): array
    {
        $ids = DB::table('status_page_links')->where('linkable_type', 'maintenance')->where('linkable_id', $maintenance->id)->pluck('status_page_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     */
    private function syncStatusPages(Maintenance $maintenance, array $ids): void
    {
        DB::table('status_page_links')->where('linkable_type', 'maintenance')->where('linkable_id', $maintenance->id)->delete();
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            DB::table('status_page_links')->insert(array_map(
                static fn (int $id): array => ['status_page_id' => $id, 'linkable_type' => 'maintenance', 'linkable_id' => $maintenance->id],
                $chunk,
            ));
        }
    }

    private function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d\TH:i:s\Z'),
            default => $value,
        };
    }

    private function maintenance(Model $model): Maintenance
    {
        return $model instanceof Maintenance ? $model : throw new \LogicException('Expected a maintenance.');
    }
}
