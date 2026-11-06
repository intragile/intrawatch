<?php

namespace App\Monitoring\Domain;

use App\Models\DomainLookup;
use Carbon\CarbonImmutable;

/**
 * The registration lookup of one registrable domain.
 */
final readonly class DomainExpiry
{
    public const SOURCE_RDAP = 'rdap';

    public const SOURCE_WHOIS = 'whois';

    public const STATUS_OK = 'ok';

    /** The registry answered but publishes no expiry date. */
    public const STATUS_UNKNOWN = 'unknown';

    /** The authoritative registry has no such domain. */
    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_ERROR = 'error';

    public function __construct(
        public string $domain,
        public string $source,
        public string $status,
        public ?CarbonImmutable $expiresAt,
        public ?string $registrar,
        public ?string $error,
        public CarbonImmutable $checkedAt,
    ) {}

    /**
     * The date in UTC, or null when its year is outside 1000 to 9999, which not every database can store.
     */
    public static function storable(?CarbonImmutable $date): ?CarbonImmutable
    {
        $utc = $date?->utc();

        return $utc !== null && $utc->year >= 1000 && $utc->year <= 9999 ? $utc : null;
    }

    public static function error(string $domain, string $source, string $reason, CarbonImmutable $checkedAt): self
    {
        return new self($domain, $source, self::STATUS_ERROR, null, null, $reason, $checkedAt);
    }

    public static function fromModel(DomainLookup $row): self
    {
        return new self(
            $row->domain,
            $row->source,
            $row->status,
            $row->expires_at === null ? null : CarbonImmutable::instance($row->expires_at)->utc(),
            $row->registrar,
            $row->error,
            CarbonImmutable::instance($row->checked_at)->utc(),
        );
    }

    /**
     * Whole days until expiry, rounded down; negative once expired. Null without an expiry date.
     */
    public function daysLeft(CarbonImmutable $now): ?int
    {
        return $this->expiresAt === null
            ? null
            : (int) floor(($this->expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);
    }

    public function isExpired(CarbonImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->lessThan($now);
    }

    /**
     * Whether the answer can still be used without asking the registry again: for a day, for an hour after an
     * error, a missing or an expired domain, and never once the expiry date it holds has passed since it was
     * fetched.
     */
    public function isFreshAt(CarbonImmutable $now, int $ttlSeconds, int $failureTtlSeconds): bool
    {
        $failed = in_array($this->status, [self::STATUS_ERROR, self::STATUS_NOT_FOUND], true) || $this->isExpired($this->checkedAt);
        $ttl = $failed ? $failureTtlSeconds : $ttlSeconds;
        if ($now->getTimestamp() - $this->checkedAt->getTimestamp() >= $ttl) {
            return false;
        }

        // A date that passed after the lookup may have been renewed since; confirm it before reporting it.
        return $this->expiresAt === null || $this->expiresAt->greaterThan($now) || $this->checkedAt->greaterThanOrEqualTo($this->expiresAt);
    }

    /**
     * @return array{domain: string, source: string, status: string, expires_at: ?string, registrar: ?string, error: ?string, checked_at: string}
     */
    public function toRow(): array
    {
        return [
            'domain' => $this->domain,
            'source' => $this->source,
            'status' => $this->status,
            'expires_at' => $this->expiresAt?->utc()->format('Y-m-d H:i:s'),
            'registrar' => $this->registrar,
            'error' => $this->error,
            'checked_at' => $this->checkedAt->utc()->format('Y-m-d H:i:s'),
        ];
    }
}
