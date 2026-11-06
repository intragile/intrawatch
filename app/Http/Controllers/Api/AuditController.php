<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Models\AuditEvent;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Read-only access to a workspace's audit trail; there is deliberately no way to alter it.
 */
class AuditController extends Controller
{
    /**
     * List audit events of the workspace, newest first. Filters: `action`, `actor_id`, `actor_type`.
     */
    public function __invoke(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAudit', $workspace);

        $request->validate([
            'action' => ['sometimes', 'string', 'max:64'],
            'actor_id' => ['sometimes', 'integer', 'min:1'],
            'actor_type' => ['sometimes', Rule::in(['user', 'token', 'system'])],
        ]);

        $query = AuditEvent::query()->where('workspace_id', $workspace->id)->orderByDesc('id');

        if ($request->has('action')) {
            $query->where('action', $request->string('action')->toString());
        }
        if ($request->has('actor_id')) {
            $query->where('actor_id', $request->integer('actor_id'));
        }
        if ($request->has('actor_type')) {
            $query->where('actor_type', $request->string('actor_type')->toString());
        }

        return CursorPage::from($query, $request, function (Model $model): array {
            /** @var AuditEvent $event */
            $event = $model;

            return [
                'id' => $event->id,
                'action' => $event->action,
                'actor_type' => $event->actor_type,
                'actor_id' => $event->actor_id,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
                'changes' => $event->changes,
                'ip' => $event->ip,
                'created_at' => $event->created_at->toIso8601String(),
            ];
        });
    }
}
