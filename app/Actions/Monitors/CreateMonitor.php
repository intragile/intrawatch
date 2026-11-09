<?php

declare(strict_types=1);

namespace App\Actions\Monitors;

use App\Actions\Concerns\RunsInWorkspace;
use App\Alerting\Engine\AlertSignals;
use App\Enums\MonitorType;
use App\Events\ChecksRecorded;
use App\Events\MonitorStatusChanged;
use App\Http\Requests\StoreMonitorRequest;
use App\Jobs\EvaluateAlerts;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use App\Monitoring\Engine\GroupEvaluator;
use App\Monitoring\Engine\PostCommit;
use App\Monitoring\Types\GroupHierarchy;
use App\Support\Audit;
use App\Support\RequestInput;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a monitor. Validation is the one of `POST /monitors`: the same form request evaluates the input, so
 * the API, the import and any other caller accept and refuse the same values with the same error keys.
 *
 * @phpstan-import-type ValidatedMonitor from StoreMonitorRequest
 */
class CreateMonitor
{
    use RunsInWorkspace;

    public function __construct(private readonly GroupEvaluator $groups) {}

    /**
     * Validates a create payload (the body of `POST /monitors`) and returns the values `handle` takes.
     * References to tags and channels are checked against the given workspace.
     *
     * @param  array<string, mixed>  $input
     * @return ValidatedMonitor
     *
     * @throws ValidationException
     */
    public function validate(Workspace $workspace, array $input): array
    {
        return $this->inWorkspace($workspace, function () use ($input): array {
            $request = StoreMonitorRequest::create('/', 'POST', RequestInput::normalize($input));
            $request->setContainer(Container::getInstance())->setRedirector(app(Redirector::class));
            $request->validateResolved();

            return $request->validatedMonitor();
        });
    }

    /**
     * Persists a validated monitor with its tags, channel bindings and audit row, and recomputes its parent
     * group. Absent channel ids (null) bind the workspace's active default channels; an empty list binds
     * none. `push_token` keeps a known token for push and cron monitors instead of generating one.
     * `$within` runs inside the transaction after everything is written, so work that must commit together
     * with the monitor can join it. A null actor records the caller of the current request, or the system.
     *
     * @param  ValidatedMonitor  $validated  The result of `validate`, optionally with `push_token`.
     * @param  (Closure(Monitor): void)|null  $within
     *
     * @throws ValidationException When the parent is missing, not a group, or too deep.
     */
    public function handle(Workspace $workspace, array $validated, ?User $actor, ?Closure $within = null): Monitor
    {
        $parent = $this->parent($validated['parent_id']);

        $monitor = new Monitor($validated['attributes'] + ['workspace_id' => $workspace->id]);
        GroupHierarchy::assertValidParent($monitor, $parent);

        if (in_array($monitor->type, [MonitorType::Push, MonitorType::Cron], true)) {
            $monitor->push_token = $validated['push_token'] ?? Str::random(48);
        }

        $events = [];
        $jobs = [];
        DB::transaction(function () use ($monitor, $validated, $workspace, $actor, $within, &$events, &$jobs): void {
            $events = [];
            $jobs = [];
            $monitor->save();
            $tags = $validated['tags'];
            if ($tags !== null) {
                $monitor->tags()->sync($tags);
            }
            $channelIds = $validated['notification_channel_ids'] ?? $this->defaultChannelIds($workspace);
            $monitor->notificationChannels()->sync($channelIds);
            Audit::record('monitor.created', $monitor, $this->createdChanges($monitor, $tags ?? []) + [
                'notification_channel_ids' => [[], $channelIds],
            ], $actor);
            if ($monitor->parent_id !== null) {
                [$events, $jobs] = $this->groupChanges([$monitor->parent_id]);
            }
            if ($within !== null) {
                $within($monitor);
            }
        });
        PostCommit::dispatch($events);
        PostCommit::queue($jobs);

        return $monitor;
    }

    private function parent(?int $id): ?Monitor
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
     * Recomputes the groups and queues the alert evaluation of their status changes; call it inside the
     * transaction. Returns the events to dispatch and the jobs to queue once it has committed.
     *
     * @param  list<int>  $groupIds
     * @return array{0: list<MonitorStatusChanged|ChecksRecorded>, 1: list<EvaluateAlerts>}
     */
    private function groupChanges(array $groupIds): array
    {
        $events = PostCommit::groupEvents($this->groups->recompute($groupIds), CarbonImmutable::now());

        return [$events, AlertSignals::queue($events)];
    }

    /**
     * Ids of the workspace's active default channels, ascending.
     *
     * @return list<int>
     */
    private function defaultChannelIds(Workspace $workspace): array
    {
        $ids = [];
        foreach ($workspace->notificationChannels()->where('is_default', true)->where('active', true)->orderBy('id')->pluck('id') as $id) {
            $ids[] = is_numeric($id) ? (int) $id : 0;
        }

        return $ids;
    }

    /**
     * @param  array<int, mixed>  $tags
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function createdChanges(Monitor $monitor, array $tags): array
    {
        $secretKeys = array_map(strval(...), array_keys($monitor->secrets ?? []));
        sort($secretKeys);

        return [
            'name' => [null, $monitor->name],
            'type' => [null, $monitor->type->value],
            'config' => [null, $monitor->config],
            'secret_keys' => [[], $secretKeys],
            'tags' => [[], array_keys($tags)],
        ];
    }
}
