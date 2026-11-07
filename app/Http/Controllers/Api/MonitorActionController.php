<?php

namespace App\Http\Controllers\Api;

use App\Enums\MonitorType;
use App\Http\Controllers\Controller;
use App\Http\Resources\MonitorPresenter;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Monitoring\MonitorActivation;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MonitorActionController extends Controller
{
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
        DB::transaction(function () use ($monitor, $active): void {
            if (MonitorActivation::apply($monitor, $active)) {
                Audit::record($active ? 'monitor.resumed' : 'monitor.paused', $monitor, ['active' => [! $active, $active]]);
            }
        });

        return response()->json(['data' => MonitorPresenter::toArray($monitor->refresh()->load(['state', 'tags']), true)]);
    }
}
