<?php

namespace Tests\Feature\Alerting;

use Tests\TestCase;

class MailConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('MAIL_TIMEOUT');
        unset($_ENV['MAIL_TIMEOUT'], $_SERVER['MAIL_TIMEOUT']);
        parent::tearDown();
    }

    public function test_empty_or_zero_mail_timeout_falls_back_to_the_default(): void
    {
        foreach (['' => 10, '0' => 10, '7' => 7] as $value => $expected) {
            putenv("MAIL_TIMEOUT={$value}");
            $_ENV['MAIL_TIMEOUT'] = (string) $value;
            $_SERVER['MAIL_TIMEOUT'] = (string) $value;

            /** @var array{mailers: array{smtp: array{timeout: int}}} $config */
            $config = require __DIR__.'/../../../config/mail.php';

            $this->assertSame($expected, $config['mailers']['smtp']['timeout'], "MAIL_TIMEOUT={$value}");
        }
    }
}
