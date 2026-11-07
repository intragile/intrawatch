<?php

namespace App\Alerting\Engine;

use Illuminate\Support\Facades\DB;

/**
 * Reads the alerting settings of monitors in one query and combines them with the global flap and suppression
 * settings.
 */
final class PolicyLoader
{
    /**
     * The monitor rows are read with a shared lock in id order, the order the result recorder and the API lock
     * them in, so a pause or delete in flight is waited for and an alert_states row is never created for a
     * monitor that is being deleted.
     *
     * @param  list<int>  $monitorIds
     * @return array<int, LoadedPolicy> Existing monitors by id, active or not.
     */
    public function load(array $monitorIds): array
    {
        if ($monitorIds === []) {
            return [];
        }
        sort($monitorIds);

        $threshold = self::int(config('alerting.flap.threshold'), 4);
        $window = self::int(config('alerting.flap.window_seconds'), 600);
        $stable = self::int(config('alerting.flap.stable_seconds'), 600);
        $recheck = self::int(config('alerting.suppression_recheck_seconds'), 60);

        $policies = [];
        foreach (array_chunk($monitorIds, 500) as $chunk) {
            $rows = DB::table('monitors')
                ->whereIn('id', $chunk)
                ->orderBy('id')
                ->sharedLock()
                ->get(['id', 'workspace_id', 'parent_id', 'active', 'resend_interval_minutes', 'notify_degraded', 'degraded_alert_after_seconds', 'notify_changes']);

            foreach ($rows as $row) {
                $id = self::int($row->id, 0);
                $policies[$id] = new LoadedPolicy(
                    $id,
                    self::int($row->workspace_id, 0),
                    $row->parent_id === null ? null : self::int($row->parent_id, 0),
                    (bool) $row->active,
                    new AlertPolicy(
                        self::int($row->resend_interval_minutes, 0),
                        (bool) $row->notify_degraded,
                        self::int($row->degraded_alert_after_seconds, 300),
                        (bool) $row->notify_changes,
                        $threshold,
                        $window,
                        $stable,
                        $recheck,
                    ),
                );
            }
        }

        return $policies;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
