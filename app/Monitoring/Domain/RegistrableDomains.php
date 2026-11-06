<?php

namespace App\Monitoring\Domain;

use Pdp\CannotProcessHost;
use Pdp\Rules;
use RuntimeException;

/**
 * Finds the registrable domain of a host with the ICANN section of the Public Suffix List.
 *
 * Only the ICANN section is used because registries register names below ICANN suffixes: `foo.github.io`
 * resolves to `github.io`, the name a registry actually holds an expiry date for.
 *
 * The list refreshed into storage takes precedence over the snapshot shipped with the application. refresh(),
 * called by the monitor loader, checks for a newer file at most once an hour, so long-running workers pick up a
 * refresh without parsing the list inside a check.
 */
final class RegistrableDomains
{
    /** Relative to the storage directory. */
    public const STORAGE_PATH = 'app/psl/public_suffix_list.dat';

    /** Relative to the application base directory. */
    public const SNAPSHOT_PATH = 'resources/psl/public_suffix_list.dat';

    private const RECHECK_SECONDS = 3600;

    /** Hosts a usable list must resolve, with the registrable domain it must resolve them to. */
    private const CANARIES = [
        'www.example.com' => 'example.com',
        'example.co.uk' => 'example.co.uk',
        'www.bbc.co.uk' => 'bbc.co.uk',
        'shop.example.de' => 'example.de',
        'intragile.hu' => 'intragile.hu',
        'foo.github.io' => 'github.io',
    ];

    private ?int $loadedMtime = null;

    private int $recheckAt;

    private function __construct(private Rules $rules, private readonly bool $reloadable)
    {
        $this->recheckAt = time() + self::RECHECK_SECONDS;
    }

    public static function load(): self
    {
        [$rules, $mtime] = self::read();
        $list = new self($rules, true);
        $list->loadedMtime = $mtime;

        return $list;
    }

    public static function fromString(string $list): self
    {
        return new self(self::parse($list), false);
    }

    /**
     * Parses a list, rejecting content that is not a complete Public Suffix List: both ICANN section markers
     * must be present and well-known hosts must resolve as expected, so a truncated download is never used.
     *
     * @throws RuntimeException
     */
    public static function parse(string $list): Rules
    {
        if (! str_contains($list, '===BEGIN ICANN DOMAINS===') || ! str_contains($list, '===END ICANN DOMAINS===')) {
            throw new RuntimeException('The content is not a complete Public Suffix List.');
        }

        try {
            $rules = Rules::fromString($list);
        } catch (CannotProcessHost $e) {
            throw new RuntimeException('The Public Suffix List cannot be parsed.', previous: $e);
        }

        foreach (self::CANARIES as $host => $expected) {
            if (self::resolve($rules, $host) !== $expected) {
                throw new RuntimeException("The Public Suffix List does not resolve {$host} to {$expected}.");
            }
        }

        return $rules;
    }

    /**
     * The registrable domain in ASCII (punycode) form, or null for an IP address, a public suffix, a name under
     * a suffix the list does not know, or anything that is not a valid host name.
     */
    public function registrable(string $host): ?string
    {
        $host = strtolower(rtrim(trim($host, '[]'), '.'));
        // Underscores are valid in DNS records but never in a registered name.
        if ($host === '' || str_contains($host, '_') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return self::resolve($this->rules, $host);
    }

    private static function resolve(Rules $rules, string $host): ?string
    {
        try {
            $domain = $rules->getICANNDomain($host)->registrableDomain()->toAscii()->value();
        } catch (CannotProcessHost) {
            return null;
        }

        return $domain === null || $domain === '' ? null : $domain;
    }

    /**
     * Reloads the list when the copy in storage changed; checks at most once an hour.
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
            [$this->rules, $this->loadedMtime] = self::read();
        } catch (RuntimeException) {
            // Keep the list already in use.
        }
    }

    /**
     * @return array{0: Rules, 1: ?int} The rules and the modification time of the storage copy they came from.
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
            throw new RuntimeException('The Public Suffix List snapshot is missing.');
        }

        return [self::parse($snapshot), null];
    }
}
