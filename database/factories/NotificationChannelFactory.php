<?php

namespace Database\Factories;

use App\Enums\ChannelType;
use App\Models\NotificationChannel;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    /**
     * A Slack channel unless a type state says otherwise.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => ChannelType::Slack,
            'config' => [],
            'secrets' => ['webhook_url' => 'https://hooks.slack.test/services/'.fake()->uuid()],
            'is_default' => false,
            'active' => true,
        ];
    }

    public function slack(): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Slack,
            'config' => [],
            'secrets' => ['webhook_url' => 'https://hooks.slack.test/services/'.fake()->uuid()],
        ]);
    }

    public function webhook(?string $url = null): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Webhook,
            'config' => ['url' => $url ?? 'https://'.fake()->domainName().'/hook', 'method' => 'POST', 'format' => 'json', 'headers' => []],
            'secrets' => null,
        ]);
    }

    /**
     * @param  list<string>  $to
     */
    public function email(array $to = ['ops@example.test']): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Email,
            'config' => ['to' => $to, 'smtp' => null],
            'secrets' => null,
        ]);
    }

    public function telegram(string $chatId = '-100123456'): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Telegram,
            'config' => ['chat_id' => $chatId, 'message_thread_id' => null, 'server_url' => 'https://api.telegram.org', 'silent' => false],
            'secrets' => ['bot_token' => '123456:'.str_repeat('a', 35)],
        ]);
    }

    public function pagerduty(): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::PagerDuty,
            'config' => [],
            'secrets' => ['routing_key' => str_repeat('a', 32)],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
