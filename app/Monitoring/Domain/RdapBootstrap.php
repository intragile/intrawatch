<?php

namespace App\Monitoring\Domain;

use RuntimeException;

/**
 * The IANA RDAP bootstrap registry for domain names (RFC 9224): which RDAP server answers for which TLD.
 *
 * The copy refreshed into storage takes precedence over the snapshot shipped with the application. refresh(),
 * called by the monitor loader, checks for a newer file at most once an hour, so long-running workers pick up a
 * refresh.
 */
final class RdapBootstrap
{
    /** Relative to the storage directory. */
    public const STORAGE_PATH = 'app/rdap/dns.json';

    /** Relative to the application base directory. */
    public const SNAPSHOT_PATH = 'resources/rdap/dns.json';

    private const RECHECK_SECONDS = 3600;

    private ?int $loadedMtime = null;

    private int $recheckAt;

    /**
     * @param  array<string, string>  $bases  Lowercase ASCII TLD to base URL ending in a slash.
     */
    private function __construct(private array $bases, private readonly bool $reloadable)
    {
        $this->recheckAt = time() + self::RECHECK_SECONDS;
    }

    public static function load(): self
    {
        [$bases, $mtime] = self::read();
        $bootstrap = new self($bases, true);
        $bootstrap->loadedMtime = $mtime;

        return $bootstrap;
    }

    /**
     * @param  array<string, string>  $bases  TLD to RDAP base URL.
     */
    public static function fromArray(array $bases): self
    {
        $normalised = [];
        foreach ($bases as $tld => $base) {
            $normalised[strtolower($tld)] = rtrim($base, '/').'/';
        }

        return new self($normalised, false);
    }

    /**
     * Parses a bootstrap file into a TLD to base URL map, preferring an https URL when a service lists several.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException When the content is not a bootstrap file.
     */
    public static function parse(string $json): array
    {
        $document = json_decode($json, true);
        if (! is_array($document) || ! is_array($document['services'] ?? null) || $document['services'] === []) {
            throw new RuntimeException('The content is not an RDAP bootstrap file.');
        }

        $bases = [];
        foreach ($document['services'] as $service) {
            if (! is_array($service) || ! is_array($service[0] ?? null) || ! is_array($service[1] ?? null)) {
                continue;
            }

            $urls = array_values(array_filter($service[1], static fn (mixed $url): bool => is_string($url) && preg_match('#^https?://#i', $url) === 1));
            usort($urls, static fn (string $a, string $b): int => (int) str_starts_with($b, 'https:') <=> (int) str_starts_with($a, 'https:'));
            if ($urls === []) {
                continue;
            }

            foreach ($service[0] as $tld) {
                if (is_string($tld) && $tld !== '') {
                    $bases[strtolower($tld)] = rtrim($urls[0], '/').'/';
                }
            }
        }

        // A usable registry always lists the largest TLD.
        if (! isset($bases['com'])) {
            throw new RuntimeException('The RDAP bootstrap file has no service for com.');
        }

        return $bases;
    }

    /**
     * The RDAP base URL for a TLD, ending in a slash, or null when the TLD has no RDAP service.
     */
    public function baseFor(string $tld): ?string
    {
        return $this->bases[strtolower($tld)] ?? null;
    }

    /**
     * Reloads the registry when the copy in storage changed; checks at most once an hour.
     */
    public function refresh(): void
    {
        if (! $this->reloadable || time() < $this->recheckAt) {
            return;
        }
        $this->recheckAt = time() + self::RECHECK_SECONDS;

        clearstatcache(true, storage_path(self::STORAGE_PATH));
        $mtime = @filemtime(storage_path(self::STORAGE_PATH));
        if (($mtime === false ? null : $mtime) === $this->loadedMtime) {
            return;
        }

        try {
            [$this->bases, $this->loadedMtime] = self::read();
        } catch (RuntimeException) {
            // Keep the registry already in use.
        }
    }

    /**
     * @return array{0: array<string, string>, 1: ?int}
     */
    private static function read(): array
    {
        $stored = storage_path(self::STORAGE_PATH);
        $contents = is_readable($stored) ? @file_get_contents($stored) : false;
        if (is_string($contents)) {
            try {
                $mtime = @filemtime($stored);

                return [self::parse($contents), $mtime === false ? null : $mtime];
            } catch (RuntimeException) {
                // A damaged copy in storage falls back to the snapshot.
            }
        }

        $snapshot = @file_get_contents(base_path(self::SNAPSHOT_PATH));
        if (! is_string($snapshot)) {
            throw new RuntimeException('The RDAP bootstrap snapshot is missing.');
        }

        return [self::parse($snapshot), null];
    }
}
