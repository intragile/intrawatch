<?php

namespace App\Http\Controllers\Api;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Http\Requests\StoreMonitorRequest;
use App\Http\Requests\UpdateMonitorRequest;
use App\Http\Resources\MonitorPresenter;
use App\Jobs\PurgeMonitorData;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Monitoring\Engine\GroupEvaluator;
use App\Monitoring\Engine\PostCommit;
use App\Monitoring\MonitorActivation;
use App\Monitoring\Types\GroupHierarchy;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MonitorController extends Controller
{
    public function __construct(private readonly GroupEvaluator $groups) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $request->validate([
            'type' => ['sometimes', Rule::enum(MonitorType::class)],
            'status' => ['sometimes', Rule::enum(MonitorStatus::class)],
            'tag' => ['sometimes', 'integer'],
            'q' => ['sometimes', 'string', 'max:120'],
            'parent_id' => ['sometimes', 'string', 'regex:/^(null|\d+)$/'],
            'active' => ['sometimes', 'in:true,false,1,0'],
        ]);

        $query = $workspace->monitors()->with(['state', 'tags'])->orderBy('monitors.id');

        $query->when($request->has('type'), fn (Builder $q) => $q->where('monitors.type', $request->string('type')->toString()));
        $query->when($request->has('status'), fn (Builder $q) => $q->whereHas(
            'state',
            fn (Builder $state) => $state->where('status', $request->string('status')->toString()),
        ));
        $query->when($request->has('tag'), fn (Builder $q) => $q->whereHas(
            'tags',
            fn (Builder $tags) => $tags->where('tags.id', $request->integer('tag')),
        ));
        $query->when($request->filled('q'), function (Builder $q) use ($request): void {
            $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($request->string('q')->toString()));
            $q->whereRaw("lower(monitors.name) like ? escape '!'", ['%'.$needle.'%']);
        });
        $query->when($request->has('parent_id'), function (Builder $q) use ($request): void {
            $parent = $request->string('parent_id')->toString();
            $parent === 'null' ? $q->whereNull('monitors.parent_id') : $q->where('monitors.parent_id', (int) $parent);
        });
        $query->when($request->has('active'), fn (Builder $q) => $q->where('monitors.active', $request->boolean('active')));

        return CursorPage::from($query, $request, fn (Model $m): array => MonitorPresenter::toArray($this->monitor($m)));
    }

    public function store(StoreMonitorRequest $request, Workspace $workspace): JsonResponse
    {
        $attributes = $request->monitorAttributes();
        $parent = $this->parent($workspace, $request->parentId());

        $monitor = new Monitor($attributes + ['workspace_id' => $workspace->id]);
        GroupHierarchy::assertValidParent($monitor, $parent);

        if (in_array($monitor->type, [MonitorType::Push, MonitorType::Cron], true)) {
            $monitor->push_token = Str::random(48);
        }

        $transitions = [];
        DB::transaction(function () use ($monitor, $request, &$transitions): void {
            $monitor->save();
            $tags = $request->tagAssignments();
            if ($tags !== null) {
                $monitor->tags()->sync($tags);
            }
            Audit::record('monitor.created', $monitor, $this->createdChanges($monitor, $tags ?? []));
            if ($monitor->parent_id !== null) {
                $transitions = $this->groups->recompute([$monitor->parent_id]);
            }
        });
        PostCommit::dispatch(PostCommit::statusEvents($transitions, CarbonImmutable::now()));

        return response()->json(['data' => MonitorPresenter::toArray($this->fresh($monitor))], 201);
    }

    public function show(Workspace $workspace, Monitor $monitor): JsonResponse
    {
        return response()->json(['data' => MonitorPresenter::toArray($this->fresh($monitor))]);
    }

    public function update(UpdateMonitorRequest $request, Workspace $workspace, Monitor $monitor): JsonResponse
    {
        $changes = $request->changes();
        $active = $changes['active'] ?? null;
        unset($changes['active']);

        if ($request->hasParentChange()) {
            GroupHierarchy::assertValidParent($monitor, $this->parent($workspace, $request->parentId()));
        }

        $transitions = [];
        DB::transaction(function () use ($request, $monitor, $changes, $active, &$transitions): void {
            $oldParentId = $monitor->parent_id;
            $before = $monitor->only(array_keys($changes));
            $oldSecretKeys = $this->secretKeys($monitor->secrets);
            $oldTags = $monitor->tagIds();
            $wasActive = $monitor->active;

            $monitor->fill($changes)->save();
            if (is_bool($active)) {
                MonitorActivation::apply($monitor, $active);
            }

            $tags = $request->tagAssignments();
            if ($tags !== null) {
                $monitor->tags()->sync($tags);
            }

            $diff = [];
            foreach ($before as $field => $old) {
                if ($field === 'secrets') {
                    continue;
                }
                $new = $monitor->getAttribute($field);
                $new = $new instanceof \BackedEnum ? $new->value : $new;
                if ($old !== $new) {
                    $diff[$field] = [$old, $new];
                }
            }
            if ($wasActive !== $monitor->active) {
                $diff['active'] = [$wasActive, $monitor->active];
            }
            $newSecretKeys = $this->secretKeys($monitor->secrets);
            if ($oldSecretKeys !== $newSecretKeys) {
                $diff['secret_keys'] = [$oldSecretKeys, $newSecretKeys];
            }
            if ($tags !== null && $oldTags !== ($newTags = $monitor->refresh()->tagIds())) {
                $diff['tags'] = [$oldTags, $newTags];
            }
            if ($diff !== []) {
                Audit::record('monitor.updated', $monitor, $diff);
            }

            $affected = [];
            if ($oldParentId !== $monitor->parent_id || $wasActive !== $monitor->active) {
                $affected = [$oldParentId, $monitor->parent_id];
            }
            if ($monitor->type === MonitorType::Group && (array_key_exists('config', $changes) || $wasActive !== $monitor->active)) {
                $affected[] = $monitor->id;
            }
            $affected = array_values(array_unique(array_filter($affected, fn (?int $id): bool => $id !== null)));
            if ($affected !== []) {
                $transitions = $this->groups->recompute($affected);
            }
        });
        PostCommit::dispatch(PostCommit::statusEvents($transitions, CarbonImmutable::now()));

        return response()->json(['data' => MonitorPresenter::toArray($this->fresh($monitor))]);
    }

    public function destroy(Workspace $workspace, Monitor $monitor): Response
    {
        $transitions = [];
        DB::transaction(function () use ($monitor, &$transitions): void {
            $monitor->delete();
            Audit::record('monitor.deleted', $monitor, ['name' => [$monitor->name, null]]);
            if ($monitor->parent_id !== null) {
                $transitions = $this->groups->recompute([$monitor->parent_id]);
            }
        });
        PostCommit::dispatch(PostCommit::statusEvents($transitions, CarbonImmutable::now()));
        PurgeMonitorData::dispatch($monitor->id)->afterCommit();

        return response()->noContent();
    }

    private function monitor(Model $model): Monitor
    {
        return $model instanceof Monitor ? $model : throw new \LogicException('Expected a monitor.');
    }

    private function fresh(Monitor $monitor): Monitor
    {
        return $monitor->refresh()->load(['state', 'tags']);
    }

    private function parent(Workspace $workspace, ?int $id): ?Monitor
    {
        if ($id === null) {
            return null;
        }

        // Looked up without the workspace filter on purpose: GroupHierarchy reports a foreign parent
        // exactly like a missing one.
        return Monitor::query()->find($id)
            ?? throw ValidationException::withMessages(['parent_id' => ['The selected parent id is invalid.']]);
    }

    /**
     * @param  array<array-key, mixed>|null  $secrets
     * @return list<string>
     */
    private function secretKeys(?array $secrets): array
    {
        $keys = array_map(strval(...), array_keys($secrets ?? []));
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<int, mixed>  $tags
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function createdChanges(Monitor $monitor, array $tags): array
    {
        return [
            'name' => [null, $monitor->name],
            'type' => [null, $monitor->type->value],
            'config' => [null, $monitor->config],
            'secret_keys' => [[], $this->secretKeys($monitor->secrets)],
            'tags' => [[], array_keys($tags)],
        ];
    }
}
