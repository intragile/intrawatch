<?php

namespace Tests\Unit\Status;

use App\Enums\DisplayStatus;
use App\Status\Pages\DayBar;
use App\Status\Pages\Svg;
use PHPUnit\Framework\TestCase;

class SvgTest extends TestCase
{
    public function test_healthy_runs_are_one_dashed_stroke_per_colour(): void
    {
        $bars = [
            new DayBar('2026-11-01', DisplayStatus::Operational, 100.0, 0),
            new DayBar('2026-11-02', DisplayStatus::Operational, 100.0, 0),
            new DayBar('2026-11-03', DisplayStatus::Unknown, null, 0),
            new DayBar('2026-11-04', DisplayStatus::Operational, 100.0, 0),
        ];

        $svg = Svg::bars($bars, fn (DayBar $bar): string => $bar->date, 'API, last 4 days');

        $this->assertStringStartsWith('<svg ', $svg);
        $this->assertStringContainsString('viewBox="0 0 20 30"', $svg);
        $this->assertStringContainsString('aria-label="API, last 4 days"><title>API, last 4 days</title>', $svg);
        $this->assertStringContainsString('<path d="M0 15h9M15 15h4" fill="none" stroke="'.Svg::color(DisplayStatus::Operational).'" stroke-width="30" stroke-dasharray="4 1"/>', $svg);
        $this->assertStringContainsString('<path d="M10 15h4" fill="none" stroke="'.Svg::color(DisplayStatus::Unknown).'"', $svg);
        $this->assertStringNotContainsString('<rect', $svg);
        $this->assertSame(1, substr_count($svg, '<title>'));
    }

    public function test_other_days_keep_their_own_tooltip(): void
    {
        $bars = [
            new DayBar('2026-11-01', DisplayStatus::Operational, 100.0, 0),
            new DayBar('2026-11-02', DisplayStatus::MajorOutage, 50.0, 720),
            new DayBar('2026-11-03', DisplayStatus::Maintenance, 100.0, 0),
            new DayBar('2026-11-04', DisplayStatus::Operational, 100.0, 0),
        ];

        $svg = Svg::bars($bars, fn (DayBar $bar): string => $bar->date);

        $this->assertSame(2, substr_count($svg, '<rect '));
        $this->assertStringContainsString('<rect x="5" width="4" height="30" fill="'.Svg::color(DisplayStatus::MajorOutage).'"><title>2026-11-02</title></rect>', $svg);
        $this->assertStringContainsString('<rect x="10" width="4" height="30" fill="'.Svg::color(DisplayStatus::Maintenance).'"><title>2026-11-03</title></rect>', $svg);
        $this->assertStringContainsString('<path d="M0 15h4M15 15h4"', $svg);
    }

    public function test_a_year_of_healthy_days_is_small(): void
    {
        $bars = [];
        for ($i = 0; $i < 365; $i++) {
            $bars[] = new DayBar(sprintf('2026-%03d', $i), DisplayStatus::Operational, 100.0, 0);
        }

        $svg = Svg::bars($bars, fn (DayBar $bar): string => $bar->date, 'API');

        $this->assertStringContainsString('<path d="M0 15h1824"', $svg);
        $this->assertLessThan(400, strlen($svg));
    }

    public function test_every_status_has_a_distinct_colour(): void
    {
        $colours = array_map(Svg::color(...), DisplayStatus::cases());

        $this->assertCount(count(DisplayStatus::cases()), array_unique($colours));
        foreach ($colours as $colour) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $colour);
        }
    }

    public function test_titles_are_escaped(): void
    {
        $hostile = '</title><script>alert("x")</script>&';
        $svg = Svg::bars([new DayBar('2026-11-01', DisplayStatus::Degraded, 99.0, 0)], fn (): string => $hostile, $hostile);

        $this->assertStringNotContainsString('<script', $svg);
        $this->assertSame(3, substr_count($svg, '&lt;/title&gt;&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&amp;'));

        $line = Svg::sparkline([1, 2], '<b>"latency"</b>');
        $this->assertStringNotContainsString('<b>', $line);
        $this->assertStringContainsString('aria-label="&lt;b&gt;&quot;latency&quot;&lt;/b&gt;"', $line);
    }

    public function test_empty_bars_render_an_empty_svg(): void
    {
        $svg = Svg::bars([], fn (): string => '');

        $this->assertStringStartsWith('<svg ', $svg);
        $this->assertStringNotContainsString('<rect', $svg);
    }

    public function test_sparkline_breaks_at_null_latency(): void
    {
        $svg = Svg::sparkline([100, 200, null, null, 50, 60, 70, null, 80], 'Latency');

        $this->assertSame(2, substr_count($svg, '<polyline '));
        // A lone sample between gaps is a dot.
        $this->assertSame(1, substr_count($svg, '<circle '));
        $this->assertStringContainsString('<title>Latency</title>', $svg);
    }

    public function test_sparkline_scales_to_the_maximum(): void
    {
        $svg = Svg::sparkline([0, 100], 'Latency');

        $this->assertMatchesRegularExpression('/points="0,28 230,2"/', $svg);
    }

    public function test_sparkline_without_samples_has_no_line(): void
    {
        $svg = Svg::sparkline([null, null, null], 'Latency');

        $this->assertStringNotContainsString('<polyline', $svg);
        $this->assertStringNotContainsString('<circle', $svg);
        $this->assertSame('', Svg::sparkline([], 'Latency'));
    }
}
