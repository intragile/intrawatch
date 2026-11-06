<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PurgeMonitorData;
use App\Models\Monitor;
use App\Models\Tag;
use App\Models\Workspace;
use App\Monitoring\MonitorActivation;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MonitorBulkController extends Controller
{
    private const ACTIONS = ['pause', 'resume', 'delete', 'tag', 'untag'];

    public function __invoke(Request $request, Workspace $workspace): JsonResponse
    {
        $needsTag = in_array($request->input('action'), ['tag', 'untag'], true);
        $request->validate([
            'action' => ['required', Rule::in(self::ACTIONS)],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
            'tag_id' => [$needsTag ? 'required' : 'prohibited', 'integer', Rule::exists('tags', 'id')->where('workspace_id', $workspace->id)],
        ]);
        $action = $request->string('action')->toString();
        $ids = [];
        foreach ($request->array('ids') as $id) {
            $ids[] = is_numeric($id) ? (int) $id : 0;
        }
        $tag = $needsTag ? Tag::query()->forWorkspace($workspace)->findOrFail($request->integer('tag_id')) : null;

        $deleted = [];
        $affected = DB::transaction(function () use ($workspace, $ids, $action, $tag, &$deleted): int {
            $monitors = $workspace->monitors()->whereIn('id', $ids)->with('state')->orderBy('id')->get();

            foreach ($monitors as $monitor) {
                match ($action) {
                    'pause', 'resume' => $this->toggle($monitor, $action === 'resume'),
                    'delete' => $this->delete($monitor, $deleted),
                    default => $this->retag($monitor, $tag ?? throw new \LogicException('Missing tag.'), $action === 'tag'),
                };
            }

            return $monitors->count();
        });

        foreach ($deleted as $id) {
            PurgeMonitorData::dispatch($id);
        }

        return response()->json(['affected' => $affected]);
    }

    private function toggle(Monitor $monitor, bool $active): void
    {
        MonitorActivation::apply($monitor, $active);
        Audit::record($active ? 'monitor.resumed' : 'monitor.paused', $monitor, ['active' => [! $active, $active]]);
    }

    /**
     * @param  list<int>  $deleted
     */
    private function delete(Monitor $monitor, array &$deleted): void
    {
        $monitor->delete();
        Audit::record('monitor.deleted', $monitor, ['name' => [$monitor->name, null]]);
        $deleted[] = $monitor->id;
    }

    private function retag(Monitor $monitor, Tag $tag, bool $attach): void
    {
        $before = $monitor->tagIds();
        $attach ? $monitor->tags()->syncWithoutDetaching([$tag->id]) : $monitor->tags()->detach($tag->id);
        Audit::record('monitor.updated', $monitor, ['tags' => [$before, $monitor->tagIds()]]);
    }
}
