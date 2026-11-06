<?php

namespace App\Monitoring\Checks;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\TimeoutCancellation;
use App\Monitoring\Domain\DomainExpiry;
use App\Monitoring\Domain\DomainExpiryService;
use App\Monitoring\Domain\NotRegistrableDomain;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Worker\Clock;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Checks when a domain's registration expires: degraded when it expires soon, down only once it has expired or
 * the registry states that it does not exist. A registry that publishes no expiry date, a lookup that fails or a
 * name that is no longer registrable never turns the monitor down.
 *
 * The lookup is answered from the service's memory or the registry; the database is never queried here.
 */
final class DomainChecker implements Checker
{
    public const NOT_PUBLISHED = 'Expiry date not published by the registry';

    public function __construct(
        private readonly DomainExpiryService $expiries,
        private readonly ?Clock $clock = null,
    ) {}

    public function check(MonitorSpec $spec, Cancellation $cancellation): CheckOutcome
    {
        $domain = is_string($spec->config['domain'] ?? null) ? $spec->config['domain'] : '';
        $timeout = $spec->timeoutSeconds;

        try {
            $expiry = $this->expiries->lookup($domain, new CompositeCancellation($cancellation, new TimeoutCancellation($timeout)));
        } catch (NotRegistrableDomain) {
            // Validation rejects such names, so this only follows a change of the Public Suffix List.
            return CheckOutcome::up(null, 'Lookup failed: Not a registrable domain');
        } catch (CancelledException) {
            $cancellation->throwIfRequested();

            return CheckOutcome::up(null, "Lookup failed: Timed out after {$timeout} s");
        } catch (Throwable $e) {
            return CheckOutcome::up(null, 'Lookup failed: '.CheckOutcome::fromThrowable($e)->message);
        }

        return self::evaluate($spec->config, $expiry, $this->now());
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function evaluate(array $config, DomainExpiry $expiry, CarbonImmutable $now): CheckOutcome
    {
        $daysLeft = $expiry->daysLeft($now);
        $facts = self::facts($expiry, $now);

        if ($expiry->status === DomainExpiry::STATUS_NOT_FOUND) {
            return CheckOutcome::down('Domain is not registered', null, $facts);
        }
        if ($expiry->status === DomainExpiry::STATUS_ERROR) {
            return CheckOutcome::up(null, 'Lookup failed: '.($expiry->error ?? 'unknown error'), $facts);
        }
        if ($daysLeft === null) {
            return CheckOutcome::up(null, self::NOT_PUBLISHED, $facts);
        }

        $watch = ['domain.expiry_band' => ExpiryThresholds::band($daysLeft, ExpiryThresholds::fromConfig($config['expiry_days'] ?? null))];
        $degradedBelow = is_int($config['degraded_below_days'] ?? null) ? $config['degraded_below_days'] : ExpiryThresholds::DEGRADED_BELOW_DAYS;

        return match (true) {
            $expiry->isExpired($now) => CheckOutcome::down(ExpiryThresholds::expiresIn('Domain', $daysLeft), null, $facts, $watch),
            $daysLeft < $degradedBelow => CheckOutcome::degraded(ExpiryThresholds::expiresIn('Domain', $daysLeft), null, $facts, $watch),
            default => CheckOutcome::up(null, '', $facts, $watch),
        };
    }

    /**
     * @return array<string, scalar|null>
     */
    public static function facts(DomainExpiry $expiry, CarbonImmutable $now): array
    {
        return [
            'domain.expires_at' => $expiry->expiresAt?->toIso8601ZuluString(),
            'domain.days_left' => $expiry->daysLeft($now),
            'domain.registrar' => $expiry->registrar,
            'domain.source' => $expiry->source,
        ];
    }

    private function now(): CarbonImmutable
    {
        return $this->clock?->now() ?? CarbonImmutable::now();
    }
}
