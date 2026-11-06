<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateWorkspace;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Jobs\RebuildDayRollups;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scoped = $user->scopedWorkspaceId();
        $query = $user->workspaces()->orderBy('workspaces.id');

        if ($scoped !== null) {
            $query->where('workspaces.id', $scoped);
        }

        return CursorPage::from($query, $request, function (Model $workspace): array {
            /** @var Workspace $workspace */
            /** @var Membership $pivot */
            $pivot = $workspace->getRelation('pivot');

            return $this->payload($workspace, $pivot->role);
        });
    }

    public function store(Request $request, CreateWorkspace $createWorkspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->authenticatedByToken(), 403, 'API tokens cannot create workspaces.');
        $request->validate(['name' => ['required', 'string', 'max:120']]);
        $name = $request->string('name')->toString();

        $workspace = $createWorkspace->handle($user, $name);

        return response()->json(['data' => $this->payload($workspace, WorkspaceRole::Owner)], 201);
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('update', $workspace);
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
        ]);
        $name = $request->string('name')->toString();
        $timezone = $request->has('timezone') ? $request->string('timezone')->toString() : $workspace->timezone;

        $previousName = $workspace->name;
        $previousTimezone = $workspace->timezone;
        DB::transaction(function () use ($workspace, $name, $timezone, $previousName, $previousTimezone): void {
            $workspace->update(['name' => $name, 'timezone' => $timezone]);

            $changes = [];
            if ($previousName !== $name) {
                $changes['name'] = [$previousName, $name];
            }
            if ($previousTimezone !== $timezone) {
                $changes['timezone'] = [$previousTimezone, $timezone];
            }
            if ($changes !== []) {
                Audit::record('workspace.updated', $workspace, $changes);
            }
        });

        if ($previousTimezone !== $timezone) {
            // Day rollups are bucketed by local midnight, so they move with the timezone.
            RebuildDayRollups::dispatch($workspace->id);
        }

        return response()->json(['data' => $this->payload($workspace, app(CurrentWorkspace::class)->role())]);
    }

    public function destroy(Workspace $workspace): Response
    {
        Gate::authorize('delete', $workspace);

        DB::transaction(function () use ($workspace): void {
            $workspace->delete();
            Audit::record('workspace.deleted', $workspace, ['name' => [$workspace->name, null]]);
        });

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Workspace $workspace, ?WorkspaceRole $role): array
    {
        return [
            'id' => $workspace->public_id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'timezone' => $workspace->timezone,
            'role' => $role?->value,
        ];
    }
}
