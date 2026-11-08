<?php

namespace App\Http\Controllers\Api;

use App\Actions\Incidents\CreateIncident;
use App\Enums\IncidentStatus;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\IncidentRequest;
use App\Http\Requests\UpdateIncidentRequest;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\Workspace;
use App\Status\Pages\AffectedPages;
use App\Status\Pages\PageStamps;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IncidentController extends Controller
{
    private const CHUNK = 500;

    private const STATES = ['open', 'resolved', 'all'];

    public function __construct(private readonly PageStamps $stamps) {}

    /**
     * Lists the incidents of the workspace, newest start first.
     *
     * `state` narrows the list to `open` (not yet resolved), `resolved`, or `all` (default). The timeline of
     * each incident is only included when a single incident is fetched.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $request->validate(['state' => ['sometimes', Rule::in(self::STATES)]]);
        $state = $request->string('state', 'all')->toString();

        $query = $workspace->incidents()->with(['monitors:id', 'statusPages:id'])
            ->orderByDesc('incidents.started_at')->orderByDesc('incidents.id');
        if ($state === 'open') {
            $query->where('incidents.status', '!=', IncidentStatus::Resolved->value);
        } elseif ($state === 'resolved') {
            $query->where('incidents.status', IncidentStatus::Resolved->value);
        }

        return CursorPage::from($query, $request, fn (Model $m): array => IncidentResource::toArray($this->incident($m)));
    }

    public function show(Workspace $workspace, Incident $incident): JsonResponse
    {
        return $this->respond($incident->id);
    }

    public function store(IncidentRequest $request, Workspace $workspace, CreateIncident $create): JsonResponse
    {
        $incident = $create->handle($workspace, CreateIncident::validated($request), null);

        return $this->respond($incident->id, 201);
    }

    public function update(UpdateIncidentRequest $request, Workspace $workspace, Incident $incident): JsonResponse
    {
        $affected = [];
        DB::transaction(function () use ($request, $incident, &$affected): void {
            $affected = AffectedPages::forIncident($incident->id);
            $oldMonitorIds = $this->monitorIds($incident);
            $oldStatusPageIds = $this->statusPageIds($incident);
            $old = $incident->only(array_keys($request->incidentAttributes()));

            $incident->fill($request->incidentAttributes());
            $diff = [];
            foreach ($incident->getDirty() as $field => $_) {
                $diff[$field] = [$this->plain($old[$field] ?? null), $this->plain($incident->getAttribute($field))];
            }

            $monitorIds = $request->monitorIds() ?? $oldMonitorIds;
            $statusPageIds = $request->statusPageIds() ?? $oldStatusPageIds;
            sort($monitorIds);
            sort($statusPageIds);
            if ($monitorIds !== $oldMonitorIds) {
                $incident->monitors()->sync($monitorIds);
                $diff['monitor_ids'] = [$oldMonitorIds, $monitorIds];
            }
            if ($statusPageIds !== $oldStatusPageIds) {
                $this->syncStatusPages($incident, $statusPageIds);
                $diff['status_page_ids'] = [$oldStatusPageIds, $statusPageIds];
            }
            $incident->save();

            if ($diff !== []) {
                Audit::record('incident.updated', $incident, $diff);
            }
        });
        // An edit can remove what a page showed (a page unlinked, a title reworded), so no last good copy of
        // the earlier content may be served during an outage.
        $this->stamps->forgetRenderings(...$affected, ...AffectedPages::forIncident($incident->id));

        return $this->respond($incident->id);
    }

    public function destroy(Workspace $workspace, Incident $incident): Response
    {
        $affected = [];
        DB::transaction(function () use ($incident, &$affected): void {
            $affected = AffectedPages::forIncident($incident->id);
            $incident->delete();
            Audit::record('incident.deleted', $incident, ['title' => [$incident->title, null]]);
        });
        $this->stamps->forgetRenderings(...$affected);

        return response()->noContent();
    }

    private function respond(int $id, int $status = 200): JsonResponse
    {
        $fresh = Incident::query()->with(['monitors:id', 'statusPages:id', 'updates'])->findOrFail($id);

        return response()->json(['data' => IncidentResource::toArray($fresh)], $status);
    }

    /**
     * @return list<int>
     */
    private function monitorIds(Incident $incident): array
    {
        $ids = DB::table('incident_monitor')->where('incident_id', $incident->id)->pluck('monitor_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();
        sort($ids);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function statusPageIds(Incident $incident): array
    {
        $ids = DB::table('status_page_links')->where('linkable_type', 'incident')->where('linkable_id', $incident->id)->pluck('status_page_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     */
    private function syncStatusPages(Incident $incident, array $ids): void
    {
        DB::table('status_page_links')->where('linkable_type', 'incident')->where('linkable_id', $incident->id)->delete();
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            DB::table('status_page_links')->insert(array_map(
                static fn (int $id): array => ['status_page_id' => $id, 'linkable_type' => 'incident', 'linkable_id' => $incident->id],
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

    private function incident(Model $model): Incident
    {
        return $model instanceof Incident ? $model : throw new \LogicException('Expected an incident.');
    }
}
