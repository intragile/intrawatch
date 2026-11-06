<?php

namespace App\Monitoring\Checks;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\MissingDnsRecordException;
use Amp\TimeoutCancellation;
use App\Monitoring\Checks\Dns\RecordFormatter;
use App\Monitoring\Checks\Dns\ResolverFactory;
use App\Monitoring\Engine\CheckOutcome;
use App\Monitoring\Engine\MonitorSpec;
use Throwable;

/**
 * Queries a record set and compares it with the expected values.
 */
final class DnsChecker implements Checker
{
    private const NXDOMAIN = 3;

    private const MAX_MESSAGE_LENGTH = 300;

    public function __construct(private readonly ResolverFactory $resolvers) {}

    public function check(MonitorSpec $spec, Cancellation $cancellation): CheckOutcome
    {
        $config = $spec->config;
        $name = is_string($config['name'] ?? null) ? $config['name'] : '';
        $typeName = is_string($config['record_type'] ?? null) ? $config['record_type'] : 'A';
        $address = is_string($config['resolver'] ?? null) ? $config['resolver'] : null;
        $type = RecordFormatter::TYPES[$typeName] ?? DnsRecord::A;
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
        $mismatch = $expected === [] ? null : $this->mismatch(is_string($config['match'] ?? null) ? $config['match'] : 'any', $expected, $values);

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
    private function mismatch(string $mode, array $expected, array $actual): ?string
    {
        $missing = array_diff($expected, $actual);
        $ok = match ($mode) {
            'all' => $missing === [],
            'exact' => $missing === [] && array_diff($actual, $expected) === [],
            default => count($missing) < count($expected),
        };

        return $ok ? null : 'Expected '.implode(', ', $expected).' got '.implode(', ', $actual);
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
