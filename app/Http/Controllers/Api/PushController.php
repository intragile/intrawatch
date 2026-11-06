<?php

namespace App\Http\Controllers\Api;

use App\Enums\MonitorType;
use App\Http\Controllers\Controller;
use App\Models\Monitor;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\RecordedBatch;
use App\Monitoring\Engine\ResultRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public endpoints that push and cron monitors report to, authenticated by the monitor's push token.
 *
 * Every miss (unknown token, inactive monitor, a route the monitor type does not offer) is the same 404, so
 * the response never tells whether a token exists.
 */
class PushController extends Controller
{
    private const MAX_MESSAGE_LENGTH = 200;

    private const MAX_LATENCY_MS = 2_147_483_647;

    private const MAX_EXIT_CODE = 255;

    public function __construct(private readonly ResultRecorder $recorder) {}

    /**
     * Report a heartbeat or a successful cron run.
     *
     * Push monitors: `status` is `up` (the default) or `down`; a reported down skips the retries. `msg` is
     * stored as the result message (longer than 200 characters is cut). `ping` is the latency in
     * milliseconds; anything but a non-negative integer is ignored. Cron monitors: records the run as up,
     * with the time since its start as latency.
     *
     * @unauthenticated
     *
     * @response array{ok: true}
     */
    public function ping(Request $request, string $token): JsonResponse
    {
        $monitor = $this->monitor($token, [MonitorType::Push, MonitorType::Cron]);

        if ($monitor->type === MonitorType::Cron) {
            return $this->finish($monitor, $request, null);
        }

        $message = $this->message($request);
        $latency = $this->latency($request);
        $outcome = $request->input('status') === 'down'
            ? CheckOutcome::down($message ?? 'Reported down', $latency)->final()
            : CheckOutcome::up($latency, $message ?? '');

        return $this->report($monitor, $outcome, []);
    }

    /**
     * Report that a cron run has started.
     *
     * Records nothing; the run's duration is measured from here and its maximum runtime is enforced.
     *
     * @unauthenticated
     *
     * @response array{ok: true}
     */
    public function start(string $token): JsonResponse
    {
        $monitor = $this->monitor($token, [MonitorType::Cron]);

        $now = CarbonImmutable::now();
        DB::table('monitor_states')->where('monitor_id', $monitor->id)->update([
            'run_started_at' => $now,
            'last_ping_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->ok();
    }

    /**
     * Report a failure.
     *
     * Records a down that skips the retries, with `msg` appended to the message when given.
     *
     * @unauthenticated
     *
     * @response array{ok: true}
     */
    public function fail(Request $request, string $token): JsonResponse
    {
        $monitor = $this->monitor($token, [MonitorType::Push, MonitorType::Cron]);

        if ($monitor->type === MonitorType::Cron) {
            return $this->finish($monitor, $request, 'Reported failure');
        }

        return $this->report($monitor, CheckOutcome::down($this->withMessage('Reported failure', $request), $this->latency($request))->final(), []);
    }

    /**
     * Report a finished cron run by its exit code.
     *
     * Exit code 0 records the run as up; 1 to 255 record a down that skips the retries.
     *
     * @unauthenticated
     *
     * @response array{ok: true}
     */
    public function exit(Request $request, string $token, string $exitCode): JsonResponse
    {
        $monitor = $this->monitor($token, [MonitorType::Cron]);
        $code = ctype_digit($exitCode) && strlen($exitCode) <= 3 ? (int) $exitCode : null;
        if ($code === null || $code > self::MAX_EXIT_CODE) {
            throw new NotFoundHttpException;
        }

        return $this->finish($monitor, $request, $code === 0 ? null : "Exit code {$code}");
    }

    /**
     * Records the end of a cron run: up when $failure is null, otherwise a final down with that message.
     */
    private function finish(Monitor $monitor, Request $request, ?string $failure): JsonResponse
    {
        $now = CarbonImmutable::now();
        $startedAt = DB::table('monitor_states')->where('monitor_id', $monitor->id)->value('run_started_at');
        $latency = null;
        if (is_string($startedAt) && $startedAt !== '') {
            $latency = (int) min(self::MAX_LATENCY_MS, max(0, $now->getTimestampMs() - CarbonImmutable::parse($startedAt)->getTimestampMs()));
        }

        $outcome = $failure === null
            ? CheckOutcome::up($latency, $this->message($request) ?? '')
            : CheckOutcome::down($this->withMessage($failure, $request), $latency)->final();

        // A failed run is a completed run too: the next one is expected from now, never this one again.
        return $this->report($monitor, $outcome, ['run_started_at' => null]);
    }

    /**
     * Records the outcome and, in the same transaction, moves the monitor's deadline to now.
     *
     * @param  array<string, mixed>  $state  Further monitor_states columns to set.
     */
    private function report(Monitor $monitor, CheckOutcome $outcome, array $state): JsonResponse
    {
        $now = CarbonImmutable::now();
        $this->recorder->record([[MonitorSpec::fromModel($monitor), $outcome, $now, null]], true, function (RecordedBatch $batch) use ($monitor, $state, $now): void {
            // Nothing is recorded for a monitor paused or deleted in the meantime, and its deadline stays.
            if (! isset($batch->transitions[$monitor->id])) {
                return;
            }
            DB::table('monitor_states')->where('monitor_id', $monitor->id)->update($state + [
                'last_ping_at' => $now,
                'deadline_anchor_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return $this->ok();
    }

    /**
     * The active monitor with this push token, if its type is one of $types; a 404 otherwise.
     *
     * @param  list<MonitorType>  $types
     */
    private function monitor(string $token, array $types): Monitor
    {
        $monitor = Monitor::query()->where('push_token', $token)->first();

        // The exact comparison also guards against case-insensitive collations.
        $found = $monitor !== null
            && is_string($monitor->push_token)
            && hash_equals($monitor->push_token, $token)
            && $monitor->active
            && in_array($monitor->type, $types, true);

        if (! $found) {
            throw new NotFoundHttpException;
        }

        return $monitor;
    }

    private function message(Request $request): ?string
    {
        $message = $request->input('msg');
        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        return mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);
    }

    private function withMessage(string $prefix, Request $request): string
    {
        $message = $this->message($request);

        return $message === null ? $prefix : "{$prefix}: {$message}";
    }

    private function latency(Request $request): ?int
    {
        $ping = $request->input('ping');
        if (is_int($ping)) {
            $ping = (string) $ping;
        }
        if (! is_string($ping) || $ping === '' || strlen($ping) > 10 || ! ctype_digit($ping)) {
            return null;
        }

        $latency = (int) $ping;

        return $latency <= self::MAX_LATENCY_MS ? $latency : null;
    }

    private function ok(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
