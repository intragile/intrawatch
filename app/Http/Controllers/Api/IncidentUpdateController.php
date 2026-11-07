<?php

namespace App\Http\Controllers\Api;

use App\Enums\IncidentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IncidentUpdateRequest;
use App\Http\Resources\IncidentUpdateResource;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Workspace;
use App\Status\Pages\AffectedPages;
use App\Status\Pages\PageStamps;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IncidentUpdateController extends Controller
{
    public function __construct(private readonly PageStamps $stamps) {}

    /**
     * Adds a timeline entry. The incident takes the entry's status; `resolved` stamps the resolution time and
     * any open status after a resolution reopens the incident.
     */
    public function store(IncidentUpdateRequest $request, Workspace $workspace, Incident $incident): JsonResponse
    {
        $status = IncidentStatus::from($request->string('status')->toString());
        $message = $request->string('message')->toString();

        $update = DB::transaction(function () use ($incident, $status, $message): IncidentUpdate {
            $locked = $this->lock($incident);
            $update = $locked->updates()->create(['status' => $status, 'message' => $message]);
            $before = $locked->status;
            $wasResolvedAt = $locked->resolved_at;

            $locked->status = $status;
            if ($status->isOpen()) {
                $locked->resolved_at = null;
            } elseif ($wasResolvedAt === null) {
                $locked->resolved_at = now();
            }
            $locked->save();

            Audit::record('incident.update_added', $locked, [
                'status' => [$before->value, $status->value],
                'update_id' => [null, $update->id],
            ]);

            return $update;
        });
        $this->bump($incident);

        return response()->json(['data' => IncidentUpdateResource::toArray($update)], 201);
    }

    /**
     * Edits the message of a timeline entry; its status and time stay as recorded.
     */
    public function update(IncidentUpdateRequest $request, Workspace $workspace, Incident $incident, IncidentUpdate $update): JsonResponse
    {
        DB::transaction(function () use ($request, $incident, $update): void {
            $old = $update->message;
            $update->message = $request->string('message')->toString();
            $update->save();

            if ($old !== $update->message) {
                Audit::record('incident.update_edited', $incident, ['update_id' => [$update->id, $update->id]]);
            }
        });
        $this->bump($incident);

        return response()->json(['data' => IncidentUpdateResource::toArray($update->refresh())]);
    }

    /**
     * Removes a timeline entry and recomputes the incident's status from the latest remaining one. The only
     * entry cannot be removed; delete the incident instead.
     */
    public function destroy(Workspace $workspace, Incident $incident, IncidentUpdate $update): Response
    {
        DB::transaction(function () use ($incident, $update): void {
            $locked = $this->lock($incident);
            if ($locked->updates()->count() <= 1) {
                throw ValidationException::withMessages(['update' => ['The only update of an incident cannot be deleted; delete the incident instead.']]);
            }
            $update->delete();

            $latest = $locked->updates()->reorder()->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
            $before = $locked->status;
            $locked->status = $latest->status;
            if ($latest->status->isOpen()) {
                $locked->resolved_at = null;
            } elseif ($locked->resolved_at === null) {
                $locked->resolved_at = $latest->created_at;
            }
            $locked->save();

            Audit::record('incident.update_deleted', $locked, [
                'status' => [$before->value, $latest->status->value],
                'update_id' => [$update->id, null],
            ]);
        });
        $this->bump($incident);

        return response()->noContent();
    }

    private function bump(Incident $incident): void
    {
        $this->stamps->bumpPage(...AffectedPages::forIncident($incident->id));
    }

    private function lock(Incident $incident): Incident
    {
        return Incident::query()->lockForUpdate()->findOrFail($incident->id);
    }
}
