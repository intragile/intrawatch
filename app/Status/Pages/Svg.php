<?php

namespace App\Status\Pages;

use App\Enums\DisplayStatus;
use Closure;

/**
 * Inline SVG for public status pages: day bars and the latency sparkline. Pure string building, so the page
 * needs no script and no external asset; every text node and attribute value is escaped.
 */
final class Svg
{
    private const BAR_WIDTH = 4;

    private const BAR_STEP = 5;

    private const BAR_HEIGHT = 30;

    private const LINE_WIDTH = 230;

    private const LINE_HEIGHT = 30;

    private const LINE_PADDING = 2;

    public static function color(DisplayStatus $status): string
    {
        return match ($status) {
            DisplayStatus::Operational => '#22a06b',
            DisplayStatus::Degraded => '#d9a400',
            DisplayStatus::PartialOutage => '#e8730c',
            DisplayStatus::MajorOutage => '#d93838',
            DisplayStatus::Maintenance => '#3b7ddd',
            DisplayStatus::Unknown => '#b8bec7',
        };
    }

    /**
     * One rectangle per day, oldest on the left, each with a `<title>` tooltip.
     *
     * @param  list<DayBar>  $bars
     * @param  Closure(DayBar): string  $title  Plain-text tooltip of a bar.
     */
    public static function bars(array $bars, Closure $title): string
    {
        $width = max(0, count($bars) * self::BAR_STEP);
        $svg = '<svg class="bars" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' '.self::BAR_HEIGHT
            .'" preserveAspectRatio="none" role="img">';
        foreach ($bars as $index => $bar) {
            $svg .= '<rect x="'.($index * self::BAR_STEP).'" y="0" width="'.self::BAR_WIDTH.'" height="'.self::BAR_HEIGHT
                .'" rx="1" fill="'.self::color($bar->status).'"><title>'.self::escape($title($bar)).'</title></rect>';
        }

        return $svg.'</svg>';
    }

    /**
     * A line of the samples scaled to the largest one, broken where a sample is missing; a lone sample between
     * gaps is drawn as a dot. Empty string when there are no sample slots at all.
     *
     * @param  list<int|null>  $values
     */
    public static function sparkline(array $values, string $title): string
    {
        $count = count($values);
        if ($count === 0) {
            return '';
        }

        $present = array_filter($values, static fn (?int $v): bool => $v !== null);
        $max = $present === [] ? 0 : max($present);
        $step = $count > 1 ? self::LINE_WIDTH / ($count - 1) : 0;
        $span = self::LINE_HEIGHT - 2 * self::LINE_PADDING;

        $runs = [];
        $run = [];
        foreach ($values as $index => $value) {
            if ($value === null) {
                if ($run !== []) {
                    $runs[] = $run;
                }
                $run = [];

                continue;
            }
            $x = self::number($index * $step);
            $y = self::number(self::LINE_HEIGHT - self::LINE_PADDING - ($max > 0 ? $value / $max * $span : 0));
            $run[] = [$x, $y];
        }
        if ($run !== []) {
            $runs[] = $run;
        }

        $label = self::escape($title);
        $svg = '<svg class="spark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.self::LINE_WIDTH.' '.self::LINE_HEIGHT
            .'" preserveAspectRatio="none" role="img" aria-label="'.$label.'"><title>'.$label.'</title>';
        foreach ($runs as $points) {
            if (count($points) === 1) {
                $svg .= '<circle cx="'.$points[0][0].'" cy="'.$points[0][1].'" r="1.5"/>';

                continue;
            }
            $svg .= '<polyline points="'.implode(' ', array_map(static fn (array $p): string => $p[0].','.$p[1], $points)).'"/>';
        }

        return $svg.'</svg>';
    }

    private static function number(float $value): string
    {
        $rounded = round($value, 1);

        return $rounded == (int) $rounded ? (string) (int) $rounded : (string) $rounded;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }
}
