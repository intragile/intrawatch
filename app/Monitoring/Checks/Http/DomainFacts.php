<?php

namespace App\Monitoring\Checks\Http;

use Amp\Cancellation;
use App\Monitoring\Checks\DomainChecker;
use App\Monitoring\Checks\ExpiryThresholds;
use App\Monitoring\Domain\DomainExpiry;
use App\Monitoring\Domain\DomainExpiryService;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Worker\Clock;

/**
 * Domain registration facts for http checks, served from the lookup cache only.
 *
 * An http check never waits for a registry: on a cache miss the lookup starts in the background, this check
 * goes without domain facts and a later one gets them. Failed and unpublished lookups add no facts.
 */
final class DomainFacts implements DomainFactsProvider
{
    public function __construct(
        private readonly DomainExpiryService $expiries,
        private readonly Clock $clock,
    ) {}

    #[\Override]
    public function inspect(MonitorSpec $spec, string $host, Cancellation $cancellation): array
    {
        $expiry = $this->expiries->peek($host);
        if ($expiry === null || $expiry->status !== DomainExpiry::STATUS_OK) {
            return ['facts' => [], 'watch' => []];
        }

        $now = $this->clock->now();
        $daysLeft = $expiry->daysLeft($now) ?? 0;

        return [
            'facts' => DomainChecker::facts($expiry, $now),
            'watch' => ['domain.expiry_band' => ExpiryThresholds::band($daysLeft, ExpiryThresholds::DEFAULT)],
        ];
    }
}
