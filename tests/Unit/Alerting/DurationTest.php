<?php

namespace Tests\Unit\Alerting;

use App\Alerting\Messages\Duration;
use App\Alerting\Messages\TextLimit;
use PHPUnit\Framework\TestCase;

class DurationTest extends TestCase
{
    public function test_duration_formats(): void
    {
        $this->assertSame('0 s', Duration::human(0));
        $this->assertSame('45 s', Duration::human(45));
        $this->assertSame('12 min 5 s', Duration::human(725));
        $this->assertSame('2 h 5 min', Duration::human(7500));
        $this->assertSame('3 d 4 h', Duration::human(273600));
        $this->assertSame('1 h', Duration::human(3600));
        $this->assertSame('1 h 5 s', Duration::human(3605));
    }

    public function test_text_limit_is_utf8_safe(): void
    {
        $this->assertSame('short', TextLimit::cut('short', 10));
        $cut = TextLimit::cut(str_repeat('é', 20), 11);
        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'));
        $this->assertLessThanOrEqual(11, strlen($cut));
        $this->assertStringEndsWith('…', $cut);
        $this->assertSame('abcdefg…', TextLimit::cut('abcdefghijkl', 10));
    }
}
