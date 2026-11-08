<?php

declare(strict_types=1);

namespace App\Import\Kuma\Mapping;

/**
 * Turns a Kuma `proxy` row into the HTTP monitor's `proxy` config and its password secret. IntraWatch
 * speaks http and socks5 proxies; any other protocol is reported back as a note.
 */
final class ProxyMapper
{
    private const PROTOCOLS = ['http', 'socks5'];

    /**
     * @param  array<string, mixed>  $row
     * @return array{proxy: array{url: string, username: string|null}, password: string|null}|Note
     */
    public function map(array $row): array|Note
    {
        $protocol = is_string($row['protocol'] ?? null) ? strtolower($row['protocol']) : '';
        if (! in_array($protocol, self::PROTOCOLS, true)) {
            return Note::of(
                'proxy_protocol_unsupported',
                'The monitor uses a '.($protocol === '' ? 'proxy without protocol' : "{$protocol} proxy")
                .', which IntraWatch does not support (http and socks5 only); it was imported paused without the proxy.',
            );
        }

        $host = is_scalar($row['host'] ?? null) ? trim((string) $row['host']) : '';
        $port = is_numeric($row['port'] ?? null) ? (int) $row['port'] : 0;
        if ($host === '' || $port < 1 || $port > 65535) {
            return Note::of('proxy_incomplete', 'The proxy of the monitor has no valid host or port; it was imported paused without the proxy.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '['.$host.']';
        }

        $username = null;
        $password = null;
        if ((bool) ($row['auth'] ?? false)) {
            $username = is_scalar($row['username'] ?? null) && (string) $row['username'] !== '' ? (string) $row['username'] : null;
            $password = is_scalar($row['password'] ?? null) && (string) $row['password'] !== '' ? (string) $row['password'] : null;
        }

        return ['proxy' => ['url' => "{$protocol}://{$host}:{$port}", 'username' => $username], 'password' => $password];
    }
}
