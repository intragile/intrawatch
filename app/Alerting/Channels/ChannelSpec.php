<?php

namespace App\Alerting\Channels;

use App\Enums\ChannelType;
use App\Models\NotificationChannel;

/**
 * The parts of a channel a sender needs, detached from the model so a send job can rebuild it from ids.
 */
final readonly class ChannelSpec
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $secrets
     */
    public function __construct(
        public int $id,
        public int $workspaceId,
        public ChannelType $type,
        public array $config,
        public array $secrets,
    ) {}

    public static function fromModel(NotificationChannel $channel): self
    {
        return new self(
            $channel->id,
            $channel->workspace_id,
            $channel->type,
            $channel->config,
            $channel->secrets ?? [],
        );
    }

    public function configString(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function secretString(string $key): ?string
    {
        $value = $this->secrets[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
