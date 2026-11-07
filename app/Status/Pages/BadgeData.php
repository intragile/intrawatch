<?php

namespace App\Status\Pages;

use App\Models\CheckRollup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the badges of one page need, computed once per cache entry: the overall status and, per component,
 * its status, its uptime over each badge window and its average latency of the last 24 hours. Stored as JSON,
 * so serving a badge reads the page cache only.
 */
final class BadgeData
{
    private const CHUNK = 500;

    public static function encode(PageView $view): string
    {
        $items = [];
        foreach ($view->sections as $section) {
            foreach ($section->items as $item) {
                foreach ([$item, ...$item->children] as $component) {
                    $items[$component->monitorId] ??= $component;
                }
            }
        }

        $today = CarbonImmutable::parse($view->generatedAt)->setTimezone($view->timezone)->startOfDay();
        $windows = self::windows(array_keys(array_filter($items, static fn (ItemView $i): bool => $i->showUptime)), $today);

        $monitors = [];
        foreach ($items as $id => $item) {
            $latency = $item->latencyHourly === null ? null : array_values(array_filter($item->latencyHourly, static fn (?int $v): bool => $v !== null));
            $monitors[(string) $id] = [
                'status' => $item->status->value,
                'uptime' => $item->showUptime ? ($windows[$id] ?? null) : null,
                'latency' => $latency === null || $latency === [] ? null : (int) round(array_sum($latency) / count($latency)),
            ];
        }

        return json_encode([
            'title' => $view->title,
            'status' => $view->overall->value,
            'monitors' => $monitors,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Uptime percent per monitor and window, null where the window holds no counted sample.
     *
     * @param  list<int>  $monitorIds
     * @return array<int, array<int, float|null>>
     */
    private static function windows(array $monitorIds, CarbonImmutable $today): array
    {
        $starts = [];
        foreach (Badge::UPTIME_DAYS as $days) {
            $starts[$days] = $today->subDays($days - 1)->getTimestamp();
        }
        $select = ['monitor_id'];
        $bindings = [];
        foreach ($starts as $days => $start) {
            $select[] = "sum(case when bucket_start >= ? then up + degraded else 0 end) as available_{$days}";
            $select[] = "sum(case when bucket_start >= ? then down else 0 end) as down_{$days}";
            array_push($bindings, $start, $start);
        }

        $result = [];
        foreach (array_chunk($monitorIds, self::CHUNK) as $chunk) {
            $rows = DB::table('check_rollups')
                ->selectRaw(implode(', ', $select), $bindings)
                ->where('bucket', CheckRollup::BUCKET_DAY)
                ->whereIn('monitor_id', $chunk)
                ->where('bucket_start', '>=', min($starts))
                ->where('bucket_start', '<', $today->addDay()->getTimestamp())
                ->groupBy('monitor_id')
                ->get();
            foreach ($rows as $row) {
                $values = (array) $row;
                $byDays = [];
                foreach (Badge::UPTIME_DAYS as $days) {
                    $byDays[$days] = DisplayStatusResolver::uptimePercent(self::int($values["available_{$days}"] ?? 0), self::int($values["down_{$days}"] ?? 0));
                }
                $result[self::int($values['monitor_id'] ?? 0)] = $byDays;
            }
        }

        return $result;
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
