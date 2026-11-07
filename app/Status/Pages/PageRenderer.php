<?php

namespace App\Status\Pages;

use App\Models\StatusPage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * Renders a page view to HTML in the page's locale: no script, no external asset, every user-supplied text
 * escaped. Only Markdown already rendered with HTML input escaped is printed raw, and custom CSS is
 * neutralised so it cannot leave its style element.
 */
final class PageRenderer
{
    private const DEFAULT_ACCENT = '#3b7ddd';

    private const MARKDOWN = ['html_input' => 'escape', 'allow_unsafe_links' => false];

    public function render(StatusPage $page, PageView $view): string
    {
        $previous = App::getLocale();
        App::setLocale($view->locale);
        // Every component shows the same days; each date label is formatted once per render.
        $dates = [];
        try {
            return view('status.page', [
                'page' => $page,
                'view' => $view,
                'url' => $page->publicUrl(),
                'accent' => self::accent($page->accent_color),
                'customCss' => self::css($page->custom_css),
                'footerHtml' => $page->footer === null || $page->footer === '' ? null : Str::markdown($page->footer, self::MARKDOWN),
                'date' => fn (CarbonImmutable $at, string $format = 'datetime'): string => self::format($at->setTimezone($view->timezone), $view->locale, $format),
                'percent' => self::percent(...),
                'barTitle' => function (DayBar $bar) use ($view, &$dates): string {
                    return $this->barTitle($bar, $dates[$bar->date] ??= self::dayLabel($bar->date, $view));
                },
            ])->render();
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * Custom CSS with every `<` escaped as a CSS escape, so nothing in it can close the style element.
     */
    public static function css(?string $css): string
    {
        return $css === null ? '' : str_replace('<', '\3C ', $css);
    }

    /**
     * A moment in the page locale, with a format from the locale's `status.formats`.
     */
    private static function format(CarbonImmutable $at, string $locale, string $format): string
    {
        $localized = $at->locale($locale);
        $pattern = __('status.formats.'.$format);

        return $localized instanceof CarbonImmutable && is_string($pattern) ? $localized->isoFormat($pattern) : $at->toDateTimeString();
    }

    /**
     * Rounded down, so anything short of 100 % never shows as 100.00 %.
     */
    private static function percent(float $value): string
    {
        return __('status.percent', [
            'value' => number_format(floor(round($value * 100, 6)) / 100, 2, __('status.decimal_point'), ''),
        ]);
    }

    private static function accent(?string $color): string
    {
        return $color !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : self::DEFAULT_ACCENT;
    }

    private static function dayLabel(string $date, PageView $view): string
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $view->timezone);

        return $day === null ? $date : self::format($day, $view->locale, 'date');
    }

    private function barTitle(DayBar $bar, string $dayLabel): string
    {
        $parts = [$dayLabel, __('status.component.'.$bar->status->value)];
        if ($bar->uptimePercent !== null) {
            $parts[] = self::percent($bar->uptimePercent);
        }

        return implode(' · ', $parts);
    }
}
