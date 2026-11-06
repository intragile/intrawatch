<?php

namespace App\Monitoring\Checks;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Dns\DnsConfigException;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\MissingDnsRecordException;
use Amp\TimeoutCancellation;
use App\Monitoring\Checks\Dns\RecordFormatter;
use App\Monitoring\Checks\Dns\ResolverFactory;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use App\Monitoring\Net\NetworkPolicy;
use Throwable;

/**
 * Queries a record set and compares it with the expected values.
 */
final class DnsChecker implements Checker
{
    private const NXDOMAIN = 3;

    private const MAX_MESSAGE_LENGTH = 300;

    public function __construct(
        private readonly ResolverFactory $resolvers,
        private readonly NetworkPolicy $policy,
    ) {}

    public function check(MonitorSpec $spec, Cancellation $cancellation): CheckOutcome
    {
        $config = $spec->config;
        $name = is_string($config['name'] ?? null) ? $config['name'] : '';
        $typeName = is_string($config['record_type'] ?? null) ? $config['record_type'] : 'A';
        $address = is_string($config['resolver'] ?? null) ? $config['resolver'] : null;
        $type = RecordFormatter::TYPES[$typeName] ?? DnsRecord::A;
        if ($address !== null && ! $this->allowed($address)) {
            return CheckOutcome::down('Blocked target');
        }

        $started = hrtime(true);

        try {
            $records = $this->resolvers->make($address)->query(
                $name,
                $type,
                new CompositeCancellation($cancellation, new TimeoutCancellation($spec->timeoutSeconds)),
            );
        } catch (CancelledException) {
            $cancellation->throwIfRequested();

            return CheckOutcome::down("Timed out after {$spec->timeoutSeconds} s");
        } catch (DnsConfigException) {
            return CheckOutcome::down('DNS query failed');
        } catch (MissingDnsRecordException) {
            return CheckOutcome::down("No {$typeName} record");
        } catch (DnsException $e) {
            return CheckOutcome::down($e->getCode() === self::NXDOMAIN ? "No {$typeName} record" : 'DNS query failed');
        } catch (Throwable $e) {
            return CheckOutcome::fromThrowable($e);
        }

        $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $values = $this->sorted(array_map(RecordFormatter::format(...), $records));
        $facts = ['dns.records' => $values];
        $watch = ($config['watch_changes'] ?? false) === true ? ['dns.records' => implode("\n", $values)] : [];

        $expected = $this->expected($config['expected'] ?? null, $typeName);
        $mismatch = $expected === [] ? null : $this->mismatch(is_string($config['match'] ?? null) ? $config['match'] : 'any', $expected, $values, $typeName);

        return $mismatch === null
            ? CheckOutcome::up($latencyMs, '', $facts, $watch)
            : CheckOutcome::down(mb_substr($mismatch, 0, self::MAX_MESSAGE_LENGTH), $latencyMs, $facts, $watch);
    }

    /**
     * @return list<string>
     */
    private function expected(mixed $configured, string $typeName): array
    {
        if (! is_array($configured)) {
            return [];
        }

        $values = [];
        foreach ($configured as $value) {
            if (is_string($value)) {
                $values[] = RecordFormatter::normalize($typeName, $value);
            }
        }

        return $this->sorted($values);
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     */
    private function mismatch(string $mode, array $expected, array $actual, string $typeName): ?string
    {
        // TXT and SPF values are joined from their character strings with commas, so a value split into
        // chunks equals the configured one once those commas are removed.
        $chunked = in_array($typeName, ['TXT', 'SPF'], true);

        $found = 0;
        foreach ($expected as $value) {
            $found += $this->contains($actual, $value, $chunked) ? 1 : 0;
        }

        $unexpected = 0;
        foreach ($actual as $value) {
            $unexpected += $this->contains($expected, $value, $chunked) ? 0 : 1;
        }

        $ok = match ($mode) {
            'all' => $found === count($expected),
            'exact' => $found === count($expected) && $unexpected === 0,
            default => $found > 0,
        };

        return $ok ? null : 'Expected '.implode(', ', $expected).' got '.implode(', ', $actual);
    }

    /**
     * @param  list<string>  $haystack
     */
    private function contains(array $haystack, string $needle, bool $chunked): bool
    {
        foreach ($haystack as $value) {
            if ($value === $needle || ($chunked && str_replace(',', '', $value) === str_replace(',', '', $needle))) {
                return true;
            }
        }

        return false;
    }

    private function allowed(string $address): bool
    {
        $ip = match (true) {
            preg_match('/^\[([^\]]+)\](?::\d+)?$/', $address, $m) === 1 => $m[1],
            substr_count($address, ':') === 1 => explode(':', $address)[0],
            default => $address,
        };

        return filter_var($ip, FILTER_VALIDATE_IP) !== false && $this->policy->allows($ip);
    }

    /**
     * @param  array<array-key, string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}
