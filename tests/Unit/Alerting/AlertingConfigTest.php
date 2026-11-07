<?php

namespace Tests\Unit\Alerting;

use PHPUnit\Framework\TestCase;

class AlertingConfigTest extends TestCase
{
    private const KEYS = ['ALERT_FLAP_THRESHOLD', 'ALERT_FLAP_WINDOW_SECONDS', 'ALERT_FLAP_STABLE_SECONDS'];

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function test_flap_settings_are_clamped_to_workable_minimums(): void
    {
        $this->setEnv('ALERT_FLAP_THRESHOLD', '1');
        $this->setEnv('ALERT_FLAP_WINDOW_SECONDS', '5');
        $this->setEnv('ALERT_FLAP_STABLE_SECONDS', '0');

        /** @var array{flap: array{threshold: int, window_seconds: int, stable_seconds: int}} $config */
        $config = require __DIR__.'/../../../config/alerting.php';

        $this->assertSame(3, $config['flap']['threshold']);
        $this->assertSame(60, $config['flap']['window_seconds']);
        $this->assertSame(60, $config['flap']['stable_seconds']);
    }

    public function test_flap_threshold_of_two_is_raised_to_three(): void
    {
        // Two class changes are a single down and recovery, which is not flapping.
        $this->setEnv('ALERT_FLAP_THRESHOLD', '2');

        /** @var array{flap: array{threshold: int}} $config */
        $config = require __DIR__.'/../../../config/alerting.php';

        $this->assertSame(3, $config['flap']['threshold']);
    }
}
