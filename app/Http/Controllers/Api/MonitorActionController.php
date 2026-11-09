<?php

namespace App\Http\Controllers\Api;

use App\Alerting\Engine\AlertSignals;
use App\Enums\MonitorType;
use App\Http\Controllers\Controller;
use App\Http\Resources\MonitorPresenter;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Monitoring\Engine\GroupEvaluator;
use App\Monitoring\Engine\PostCommit;
use App\Monitoring\MonitorActivation;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MonitorActionController extends Controller
{
    public function __construct(private readonly GroupEvaluator $groups) {}

    public function pause(Workspace $workspace, Monitor $monitor): JsonResponse
    {
        return $this->toggle($monitor, false);
    }

    public function resume(Workspace $workspace, Monitor $monitor): JsonResponse
    {
        return $this->toggle($monitor, true);
    }

    public function check(Workspace $workspace, Monitor $monitor): JsonResponse
    {
        if (! $monitor->type->definition()->isScheduled()) {
            throw ValidationException::withMessages(['type' => ['This monitor type is not checked on demand.']]);
        }

        DB::transaction(function () use ($monitor): void {
            $monitor->forceFill(['check_requested_at' => now()])->save();
            Audit::record('monitor.check_requested', $monitor);
        });

        return response()->json(['check_requested_at' => $monitor->check_requested_at?->toIso8601String()], 202);
    }

    public function rotatePushToken(Workspace $workspace, Monitor $monitor): JsonResponse
    {
        if (! in_array($monitor->type, [MonitorType::Push, MonitorType::Cron], true)) {
            throw ValidationException::withMessages(['type' => ['Only push and cron monitors have a push token.']]);
        }

        DB::transaction(function () use ($monitor): void {
            $monitor->forceFill(['push_token' => Str::random(48)])->save();
            Audit::record('monitor.push_token_rotated', $monitor);
        });

        return response()->json(['push_url' => MonitorPresenter::pushUrl($monitor)]);
    }

    private function toggle(Monitor $monitor, bool $active): JsonResponse
    {
        $events = [];
        $jobs = [];
        DB::transaction(function () use ($monitor, $active, &$events, &$jobs): void {
            $events = [];
            $jobs = [];
            if (! MonitorActivation::apply($monitor, $active)) {
                return;
            }
            Audit::record($active ? 'monitor.resumed' : 'monitor.paused', $monitor, ['active' => [! $active, $active]]);

            // The parent group, and a paused or resumed group itself, are re-evaluated for the new activity.
            $affected = array_values(array_filter([$monitor->parent_id, $monitor->type === MonitorType::Group ? $monitor->id : null], fn (?int $id): bool => $id !== null));
            if ($affected !== []) {
                $events = PostCommit::groupEvents($this->groups->recompute($affected), CarbonImmutable::now());
                $jobs = AlertSignals::queue($events);
            }
        });
        PostCommit::dispatch($events);
        PostCommit::queue($jobs);

        return response()->json(['data' => MonitorPresenter::toArray($monitor->refresh()->load(['state', 'tags', 'notificationChannels:id']), true)]);
    }
}
