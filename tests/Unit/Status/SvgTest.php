<?php

namespace Tests\Unit\Status;

use App\Enums\DisplayStatus;
use App\Status\Pages\DayBar;
use App\Status\Pages\Svg;
use PHPUnit\Framework\TestCase;

class SvgTest extends TestCase
{
    public function test_one_rect_per_bar_coloured_by_status(): void
    {
        $bars = [
            new DayBar('2026-11-01', DisplayStatus::Operational, 100.0, 0),
            new DayBar('2026-11-02', DisplayStatus::MajorOutage, 50.0, 720),
            new DayBar('2026-11-03', DisplayStatus::Unknown, null, 0),
        ];

        $svg = Svg::bars($bars, fn (DayBar $bar): string => $bar->date);

        $this->assertSame(3, substr_count($svg, '<rect '));
        $this->assertSame(3, substr_count($svg, '<title>'));
        $this->assertStringStartsWith('<svg ', $svg);
        $this->assertStringContainsString('viewBox="0 0 15 30"', $svg);
        $this->assertMatchesRegularExpression('/<rect x="0"[^>]*fill="'.preg_quote(Svg::color(DisplayStatus::Operational), '/').'"><title>2026-11-01<\/title>/', $svg);
        $this->assertMatchesRegularExpression('/<rect x="5"[^>]*fill="'.preg_quote(Svg::color(DisplayStatus::MajorOutage), '/').'"><title>2026-11-02<\/title>/', $svg);
        $this->assertMatchesRegularExpression('/<rect x="10"[^>]*fill="'.preg_quote(Svg::color(DisplayStatus::Unknown), '/').'"><title>2026-11-03<\/title>/', $svg);
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
        $svg = Svg::bars([new DayBar('2026-11-01', DisplayStatus::Operational, 100.0, 0)], fn (): string => '</title><script>alert("x")</script>&');

        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringContainsString('&lt;/title&gt;&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&amp;', $svg);

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
