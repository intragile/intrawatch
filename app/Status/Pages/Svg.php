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
     * One bar per day, oldest on the left. Runs of consecutive days without a tooltip of their own (operational
     * and unknown days) are drawn as one dashed stroke per colour, so a long healthy history costs a few bytes;
     * every other day is its own rectangle with a `<title>` tooltip. The whole graphic carries a summary title.
     *
     * @param  list<DayBar>  $bars
     * @param  Closure(DayBar): string  $title  Plain-text tooltip of a day that has one.
     * @param  string  $summary  Accessible name and tooltip of the whole graphic.
     */
    public static function bars(array $bars, Closure $title, string $summary = ''): string
    {
        $width = count($bars) * self::BAR_STEP;
        $label = self::escape($summary);
        $svg = '<svg class="bars" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' '.self::BAR_HEIGHT
            .'" preserveAspectRatio="none" role="img"'.($summary === '' ? '' : ' aria-label="'.$label.'"').'>'
            .($summary === '' ? '' : '<title>'.$label.'</title>');

        // Subpaths by colour: one dashed stroke per run of consecutive same-coloured days without a tooltip.
        $runs = [];
        $rects = '';
        $runStart = 0;
        $runColor = null;
        $count = count($bars);
        for ($index = 0; $index <= $count; $index++) {
            $bar = $bars[$index] ?? null;
            $color = $bar === null || self::hasTooltip($bar->status) ? null : self::color($bar->status);
            if ($runColor !== null && $color !== $runColor) {
                $length = ($index - $runStart) * self::BAR_STEP - (self::BAR_STEP - self::BAR_WIDTH);
                $runs[$runColor][] = 'M'.($runStart * self::BAR_STEP).' '.(self::BAR_HEIGHT / 2).'h'.$length;
            }
            if ($color !== null && $color !== $runColor) {
                $runStart = $index;
            }
            $runColor = $color;
            if ($bar !== null && $color === null) {
                $rects .= '<rect x="'.($index * self::BAR_STEP).'" width="'.self::BAR_WIDTH.'" height="'.self::BAR_HEIGHT
                    .'" fill="'.self::color($bar->status).'"><title>'.self::escape($title($bar)).'</title></rect>';
            }
        }

        foreach ($runs as $color => $subpaths) {
            $svg .= '<path d="'.implode('', $subpaths).'" fill="none" stroke="'.$color.'" stroke-width="'.self::BAR_HEIGHT
                .'" stroke-dasharray="'.self::BAR_WIDTH.' '.(self::BAR_STEP - self::BAR_WIDTH).'"/>';
        }

        return $svg.$rects.'</svg>';
    }

    /**
     * Days worth a tooltip of their own: anything but a normal or an empty day.
     */
    public static function hasTooltip(DisplayStatus $status): bool
    {
        return $status !== DisplayStatus::Operational && $status !== DisplayStatus::Unknown;
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
