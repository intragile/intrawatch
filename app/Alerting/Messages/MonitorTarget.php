<?php

namespace App\Alerting\Messages;

use App\Enums\MonitorType;
use App\Models\Monitor;

/**
 * A short, credential-free description of what a monitor checks.
 */
final class MonitorTarget
{
    public static function describe(Monitor $monitor): string
    {
        $config = $monitor->config;

        return match ($monitor->type) {
            MonitorType::Http => self::url(self::string($config, 'url')),
            MonitorType::Tcp, MonitorType::Tls => self::string($config, 'host').':'.self::string($config, 'port'),
            MonitorType::Ping => self::string($config, 'host'),
            MonitorType::Dns => self::string($config, 'name').' ('.strtoupper(self::string($config, 'record_type')).')',
            MonitorType::Domain => self::string($config, 'domain'),
            MonitorType::Push, MonitorType::Cron, MonitorType::Group => $monitor->type->value,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function string(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private static function url(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return '-';
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host = $parts['host'];
        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port !== null && $port !== $default ? ':'.$port : '').($parts['path'] ?? '');
    }
}
