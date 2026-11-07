<?php

namespace App\Http\Controllers\Api;

use App\Enums\MonitorType;
use App\Http\Controllers\Controller;
use App\Models\Monitor;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\DatabaseErrors;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Engine\RecordedBatch;
use App\Monitoring\Engine\ResultRecorder;
use App\Monitoring\Engine\SafeText;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use PDOException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Public endpoints that push and cron monitors report to, authenticated by the monitor's push token.
 *
 * Every miss (unknown token, inactive monitor, a route the monitor type does not offer) is the same 404, so
 * the response never tells whether a token exists. Misses are also limited per client address; a valid
 * token is never refused because of them. A request that loses a deadlock against a concurrent writer (the
 * check worker recording the same group, say) is retried up to MAX_DEADLOCK_RETRIES times; any other database
 * error, or one more deadlock, is a 503 that names neither query nor token.
 */
class PushController extends Controller
{
    private const MAX_MESSAGE_LENGTH = 200;

    private const MAX_LATENCY_MS = 2_147_483_647;

    private const MAX_EXIT_CODE = 255;

    private const MISSES_PER_MINUTE = 60;

    private const MAX_DEADLOCK_RETRIES = 3;

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
        return $this->guarded(function () use ($request, $token): JsonResponse {
            $monitor = $this->monitor($request, $token, [MonitorType::Push, MonitorType::Cron]);

            if ($monitor->type === MonitorType::Cron) {
                return $this->finish($monitor, $request, null);
            }

            $message = $this->message($request);
            $latency = $this->latency($request);
            $status = $request->input('status');
            $outcome = is_string($status) && strtolower(trim($status)) === 'down'
                ? CheckOutcome::down($message ?? 'Reported down', $latency)->final()
                : CheckOutcome::up($latency, $message ?? '');

            return $this->report($monitor, $outcome);
        });
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
    public function start(Request $request, string $token): JsonResponse
    {
        return $this->guarded(function () use ($request, $token): JsonResponse {
            $monitor = $this->monitor($request, $token, [MonitorType::Cron]);

            $now = CarbonImmutable::now();
            DB::table('monitor_states')->where('monitor_id', $monitor->id)->update([
                'run_started_at' => $now,
                'last_ping_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->ok();
        });
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
        return $this->guarded(function () use ($request, $token): JsonResponse {
            $monitor = $this->monitor($request, $token, [MonitorType::Push, MonitorType::Cron]);

            if ($monitor->type === MonitorType::Cron) {
                return $this->finish($monitor, $request, 'Reported failure');
            }

            return $this->report($monitor, CheckOutcome::down($this->withMessage('Reported failure', $request), $this->latency($request))->final());
        });
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
        return $this->guarded(function () use ($request, $token, $exitCode): JsonResponse {
            $monitor = $this->monitor($request, $token, [MonitorType::Cron]);
            $code = ctype_digit($exitCode) && strlen($exitCode) <= 3 ? (int) $exitCode : null;
            if ($code === null || $code > self::MAX_EXIT_CODE) {
                throw new NotFoundHttpException;
            }

            return $this->finish($monitor, $request, $code === 0 ? null : "Exit code {$code}");
        });
    }

    /**
     * Runs a request body, retrying it after a deadlock and turning database errors into a 503. Every write
     * of the body happens in one transaction that the deadlock rolled back, so running it again is safe.
     * Query exceptions carry the SQL and possibly its bindings, the push token among them, so neither is
     * logged nor rendered.
     *
     * @param  Closure(): JsonResponse  $handle
     */
    private function guarded(Closure $handle): JsonResponse
    {
        for ($retries = 0; ; $retries++) {
            try {
                return $handle();
            } catch (QueryException|PDOException $e) {
                if ($retries < self::MAX_DEADLOCK_RETRIES && DatabaseErrors::isDeadlock($e)) {
                    // A short random pause keeps the retry from meeting the same writer in the same order.
                    usleep(random_int(5_000, 25_000));

                    continue;
                }

                Log::error('A push request failed on the database.', [
                    ...DatabaseErrors::context($e),
                    'retries' => $retries,
                ]);

                throw new ServiceUnavailableHttpException(null, 'The service is temporarily unavailable.');
            }
        }
    }

    /**
     * Records the end of a cron run: up when $failure is null, otherwise a final down with that message.
     */
    private function finish(Monitor $monitor, Request $request, ?string $failure): JsonResponse
    {
        $now = CarbonImmutable::now();
        $startedAt = DB::table('monitor_states')->where('monitor_id', $monitor->id)->value('run_started_at');
        $startedAt = is_string($startedAt) && $startedAt !== '' ? $startedAt : null;
        $latency = null;
        if ($startedAt !== null) {
            $latency = (int) min(self::MAX_LATENCY_MS, max(0, $now->getTimestampMs() - CarbonImmutable::parse($startedAt)->getTimestampMs()));
        }

        $outcome = $failure === null
            ? CheckOutcome::up($latency, $this->message($request) ?? '')
            : CheckOutcome::down($this->withMessage($failure, $request), $latency)->final();

        // A failed run is a completed run too: the next one is expected from now, never this one again. Only
        // the run this report measured is cleared; a run that started meanwhile stays in progress.
        return $this->report($monitor, $outcome, $startedAt);
    }

    /**
     * Records the outcome and, in the same transaction, moves the monitor's deadline to now.
     *
     * @param  ?string  $finishedRun  The stored start of the cron run this report finishes, which is cleared.
     */
    private function report(Monitor $monitor, CheckOutcome $outcome, ?string $finishedRun = null): JsonResponse
    {
        $now = CarbonImmutable::now();
        $this->recorder->record([[MonitorSpec::fromModel($monitor), $outcome, $now, null]], true, function (RecordedBatch $batch) use ($monitor, $finishedRun, $now): void {
            // Nothing is recorded for a monitor paused or deleted in the meantime, and its deadline stays.
            if (! isset($batch->transitions[$monitor->id])) {
                return;
            }
            DB::table('monitor_states')->where('monitor_id', $monitor->id)->update([
                'last_ping_at' => $now,
                'deadline_anchor_at' => $now,
                'updated_at' => $now,
            ]);
            if ($finishedRun !== null) {
                DB::table('monitor_states')
                    ->where('monitor_id', $monitor->id)
                    ->where('run_started_at', $finishedRun)
                    ->update(['run_started_at' => null]);
            }
        });

        return $this->ok();
    }

    /**
     * The active monitor with this push token, if its type is one of $types; a 404 otherwise, or a 429 once
     * the client address has used up its misses.
     *
     * @param  list<MonitorType>  $types
     */
    private function monitor(Request $request, string $token, array $types): Monitor
    {
        $monitor = Monitor::query()->where('push_token', $token)->first();

        // The exact comparison also guards against case-insensitive collations.
        $found = $monitor !== null
            && is_string($monitor->push_token)
            && hash_equals($monitor->push_token, $token)
            && $monitor->active
            && in_array($monitor->type, $types, true);

        if (! $found) {
            $key = 'push-miss:'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, self::MISSES_PER_MINUTE)) {
                throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
            }
            RateLimiter::hit($key, 60);

            throw new NotFoundHttpException;
        }

        return $monitor;
    }

    /**
     * The reported message, made storable (see SafeText) and cut to MAX_MESSAGE_LENGTH characters.
     */
    private function message(Request $request): ?string
    {
        $message = $request->input('msg');
        $message = is_string($message) ? SafeText::clean($message) : '';
        if (trim($message) === '') {
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
