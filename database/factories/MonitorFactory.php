<?php

namespace Database\Factories;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Monitor>
 */
class MonitorFactory extends Factory
{
    /**
     * An HTTP monitor unless a type state says otherwise.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->domainName(),
            'type' => MonitorType::Http,
            'active' => true,
            'interval_seconds' => 60,
            'timeout_seconds' => 10,
            'retries' => 1,
            'retry_interval_seconds' => 60,
            'inverted' => false,
            'config' => ['url' => 'https://'.fake()->domainName()],
        ];
    }

    public function http(?string $url = null): static
    {
        return $this->state(fn (): array => [
            'type' => MonitorType::Http,
            'config' => ['url' => $url ?? 'https://'.fake()->domainName()],
        ]);
    }

    public function tcp(?string $host = null, int $port = 443): static
    {
        return $this->state(fn (): array => [
            'type' => MonitorType::Tcp,
            'config' => ['host' => $host ?? fake()->domainName(), 'port' => $port],
        ]);
    }

    public function ping(?string $host = null): static
    {
        return $this->state(fn (): array => [
            'type' => MonitorType::Ping,
            'config' => ['host' => $host ?? fake()->domainName(), 'count' => 1],
        ]);
    }

    public function dns(?string $name = null, string $recordType = 'A'): static
    {
        return $this->state(fn (): array => [
            'type' => MonitorType::Dns,
            'config' => ['name' => $name ?? fake()->domainName(), 'record_type' => $recordType],
        ]);
    }

    public function tls(?string $host = null): static
    {
        return $this->state(fn (): array => [
            'type' => MonitorType::Tls,
            'interval_seconds' => 3600,
            'retry_interval_seconds' => 3600,
            'config' => ['host' => $host ?? fake()->domainName(), 'port' => 443],
        ]);
    }

    public function domain(?string $domain = null): static
    {
        return $this->state(fn (): array => [
            'type' => MonitorType::Domain,
            'interval_seconds' => 3600,
            'retry_interval_seconds' => 3600,
            'config' => ['domain' => $domain ?? fake()->domainName()],
        ]);
    }

    public function push(int $graceSeconds = 30): static
    {
        return $this->state(fn (): array => [
            'type' => MonitorType::Push,
            'push_token' => Str::random(48),
            'config' => ['grace_seconds' => $graceSeconds],
        ]);
    }

    public function cron(string $schedule = '*/5 * * * *', string $timezone = 'UTC', int $graceSeconds = 300): static
    {
        return $this->state(fn (): array => [
            'type' => MonitorType::Cron,
            'push_token' => Str::random(48),
            'config' => ['schedule' => $schedule, 'timezone' => $timezone, 'grace_seconds' => $graceSeconds],
        ]);
    }

    public function group(string $mode = 'all', ?int $quorum = null): static
    {
        return $this->state(fn (): array => [
            'type' => MonitorType::Group,
            'config' => ['mode' => $mode] + ($quorum !== null ? ['quorum' => $quorum] : []),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
