<?php

namespace Tests\Unit\Monitoring;

use App\Monitoring\Checks\ExpiryThresholds;
use PHPUnit\Framework\TestCase;

class ExpiryThresholdsTest extends TestCase
{
    public function test_expiry_bands(): void
    {
        $t = [30, 14, 7, 1];
        $this->assertSame('ok', ExpiryThresholds::band(45, $t));
        $this->assertSame('30', ExpiryThresholds::band(30, $t));
        $this->assertSame('7', ExpiryThresholds::band(3, $t));
        $this->assertSame('expired', ExpiryThresholds::band(-1, $t));
    }

    public function test_band_edges_and_unsorted_thresholds(): void
    {
        $t = [7, 30, 1, 14];
        $this->assertSame('30', ExpiryThresholds::band(15, $t));
        $this->assertSame('14', ExpiryThresholds::band(14, $t));
        $this->assertSame('1', ExpiryThresholds::band(1, $t));
        $this->assertSame('1', ExpiryThresholds::band(0, $t));
        $this->assertSame('ok', ExpiryThresholds::band(31, $t));
        $this->assertSame('ok', ExpiryThresholds::band(5, []));
        $this->assertSame('expired', ExpiryThresholds::band(-30, []));
    }

    public function test_expiry_messages(): void
    {
        $this->assertSame('Certificate expires in 3 days', ExpiryThresholds::expiresIn('Certificate', 3));
        $this->assertSame('Certificate expires in 1 day', ExpiryThresholds::expiresIn('Certificate', 1));
        $this->assertSame('Certificate expires today', ExpiryThresholds::expiresIn('Certificate', 0));
        $this->assertSame('Certificate expired', ExpiryThresholds::expiresIn('Certificate', -40));
    }
}
