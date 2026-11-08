<?php

namespace Tests\Feature;

use Laravel\Reverb\ApplicationManager;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Tests\TestCase;

class ReverbConfigTest extends TestCase
{
    private const VARS = ['APP_URL', 'REVERB_ALLOWED_ORIGINS', 'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET'];

    /** @var array<string, array{string|false, mixed, mixed}> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::VARS as $name) {
            $this->saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$env, $envArray, $server]) {
            $env === false ? putenv($name) : putenv("{$name}={$env}");
            $this->restore($_ENV, $name, $envArray);
            $this->restore($_SERVER, $name, $server);
        }

        parent::tearDown();
    }

    /**
     * @param  array<array-key, mixed>  $array
     */
    private function restore(array &$array, string $name, mixed $value): void
    {
        if ($value === null) {
            unset($array[$name]);
        } else {
            $array[$name] = $value;
        }
    }

    /**
     * env() reads $_SERVER, $_ENV and the process environment; .env values sit in the first two.
     */
    private function setEnv(string $name, ?string $value): void
    {
        $value === null ? putenv($name) : putenv("{$name}={$value}");
        $this->restore($_ENV, $name, $value);
        $this->restore($_SERVER, $name, $value);
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(?string $appUrl, ?string $extra = null): array
    {
        $this->setEnv('APP_URL', $appUrl);
        $this->setEnv('REVERB_ALLOWED_ORIGINS', $extra);

        // Evaluated directly: refreshApplication() reloads .env, whose loader overwrites the keys it set itself.
        $config = require config_path('reverb.php');
        config(['reverb' => $config]);
        $this->app->forgetInstance(ApplicationManager::class);
        $this->app->forgetInstance(ApplicationProvider::class);

        $origins = config('reverb.apps.apps.0.allowed_origins');
        $this->assertIsArray($origins);

        /** @var list<string> $origins */
        return $origins;
    }

    public function test_default_is_the_app_url_host_not_a_wildcard(): void
    {
        $origins = $this->allowedOrigins('https://status.example.com:8443');

        $this->assertSame(['status.example.com'], $origins);
        $this->assertNotContains('*', $origins);
    }

    public function test_extra_origins_from_the_environment_are_added(): void
    {
        $this->assertSame(
            ['status.example.com', 'intrawatch.lan', '*.example.org'],
            $this->allowedOrigins('https://status.example.com', ' intrawatch.lan, *.example.org ,status.example.com,'),
        );
    }

    public function test_hosts_are_lowercased_because_reverb_compares_origins_case_sensitively(): void
    {
        // Browsers send the Origin host in lowercase; Reverb matches it with a case-sensitive Str::is.
        $this->assertSame(
            ['intrawatch.example.com', 'ops.example.org'],
            $this->allowedOrigins('https://IntraWatch.Example.com', 'Ops.Example.org'),
        );
    }

    public function test_unparseable_or_empty_app_url_falls_back_to_localhost(): void
    {
        $this->assertSame(['localhost'], $this->allowedOrigins('not a url'));
        $this->assertSame(['localhost'], $this->allowedOrigins(''));
    }

    public function test_explicit_wildcard_is_honoured(): void
    {
        $this->assertSame(['status.example.com', '*'], $this->allowedOrigins('https://status.example.com', '*'));
    }

    public function test_reverb_application_reports_the_same_origins(): void
    {
        $this->setEnv('REVERB_APP_ID', '123456');
        $this->setEnv('REVERB_APP_KEY', $key = 'reverb-config-test-key');
        $this->setEnv('REVERB_APP_SECRET', 'reverb-config-test-secret');
        $this->allowedOrigins('http://intrawatch.test:3000', 'other.test');

        $app = $this->app->make(ApplicationProvider::class)->findByKey($key);

        $this->assertSame(['intrawatch.test', 'other.test'], $app->allowedOrigins());
    }
}
