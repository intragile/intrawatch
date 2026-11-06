<?php

namespace Tests\Support;

use App\Enums\MonitorType;
use App\Monitoring\Engine\MonitorSpec;

/**
 * Builds monitor specs for tests that exercise the engine without a database.
 */
final class MonitorSpecFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public static function http(
        int $retries = 1,
        int $intervalSeconds = 60,
        int $retryIntervalSeconds = 30,
        ?int $degradedThresholdMs = null,
        bool $inverted = false,
        int $id = 1,
        int $workspaceId = 1,
        ?int $parentId = null,
        array $config = ['url' => 'https://example.test'],
    ): MonitorSpec {
        return self::of(MonitorType::Http, $config, $retries, $intervalSeconds, $retryIntervalSeconds, $degradedThresholdMs, $inverted, $id, $workspaceId, $parentId);
    }

    public static function tls(int $retries = 1, bool $inverted = false, int $id = 1, int $workspaceId = 1): MonitorSpec
    {
        return self::of(MonitorType::Tls, ['host' => 'example.test', 'port' => 443], $retries, 3600, 3600, null, $inverted, $id, $workspaceId, null);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function of(
        MonitorType $type,
        array $config,
        int $retries = 1,
        int $intervalSeconds = 60,
        int $retryIntervalSeconds = 30,
        ?int $degradedThresholdMs = null,
        bool $inverted = false,
        int $id = 1,
        int $workspaceId = 1,
        ?int $parentId = null,
    ): MonitorSpec {
        return new MonitorSpec(
            id: $id,
            workspaceId: $workspaceId,
            type: $type,
            config: $config,
            secrets: [],
            intervalSeconds: $intervalSeconds,
            timeoutSeconds: 10,
            retries: $retries,
            retryIntervalSeconds: $retryIntervalSeconds,
            degradedThresholdMs: $degradedThresholdMs,
            inverted: $inverted,
            parentId: $parentId,
            updatedAt: 0,
            pushToken: null,
        );
    }
}
