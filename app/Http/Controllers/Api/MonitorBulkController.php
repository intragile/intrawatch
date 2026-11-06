<?php

namespace App\Http\Controllers\Api;

use App\Enums\MonitorType;
use App\Http\Controllers\Controller;
use App\Jobs\PurgeMonitorData;
use App\Models\Monitor;
use App\Models\Tag;
use App\Models\Workspace;
use App\Monitoring\Engine\GroupEvaluator;
use App\Monitoring\Engine\PostCommit;
use App\Monitoring\MonitorActivation;
use App\Monitoring\Types\GroupHierarchy;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MonitorBulkController extends Controller
{
    private const ACTIONS = ['pause', 'resume', 'delete', 'tag', 'untag'];

    public function __construct(private readonly GroupEvaluator $groups) {}

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
        $transitions = [];
        $affected = DB::transaction(function () use ($workspace, $ids, $action, $tag, &$deleted, &$transitions): int {
            $monitors = $workspace->monitors()->whereIn('id', $ids)->with('state')->orderBy('id')->get();

            $regroup = [];
            foreach ($monitors as $monitor) {
                if (in_array($action, ['pause', 'resume', 'delete'], true)) {
                    $regroup[] = $monitor->parent_id;
                }
                if ($action !== 'delete' && $monitor->type === MonitorType::Group) {
                    $regroup[] = $monitor->id;
                }
                match ($action) {
                    'pause', 'resume' => $this->toggle($monitor, $action === 'resume'),
                    'delete' => $this->delete($monitor, $deleted),
                    default => $this->retag($monitor, $tag ?? throw new \LogicException('Missing tag.'), $action === 'tag'),
                };
            }

            $regroup = array_values(array_unique(array_filter($regroup, fn (?int $id): bool => $id !== null)));
            if ($regroup !== []) {
                $transitions = $this->groups->recompute($regroup);
            }

            return $monitors->count();
        });

        PostCommit::dispatch(PostCommit::statusEvents($transitions, CarbonImmutable::now()));

        foreach ($deleted as $id) {
            PurgeMonitorData::dispatch($id);
        }

        return response()->json(['affected' => $affected]);
    }

    private function toggle(Monitor $monitor, bool $active): void
    {
        if (MonitorActivation::apply($monitor, $active)) {
            Audit::record($active ? 'monitor.resumed' : 'monitor.paused', $monitor, ['active' => [! $active, $active]]);
        }
    }

    /**
     * @param  list<int>  $deleted
     */
    private function delete(Monitor $monitor, array &$deleted): void
    {
        GroupHierarchy::detachChildren($monitor);
        $monitor->delete();
        Audit::record('monitor.deleted', $monitor, ['name' => [$monitor->name, null]]);
        $deleted[] = $monitor->id;
    }

    private function retag(Monitor $monitor, Tag $tag, bool $attach): void
    {
        $before = $monitor->tagIds();
        $attach ? $monitor->tags()->syncWithoutDetaching([$tag->id]) : $monitor->tags()->detach($tag->id);
        $after = $monitor->tagIds();
        if ($before !== $after) {
            Audit::record('monitor.updated', $monitor, ['tags' => [$before, $after]]);
        }
    }
}
