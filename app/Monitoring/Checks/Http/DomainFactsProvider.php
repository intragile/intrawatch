<?php

namespace App\Monitoring\Checks\Http;

use Amp\Cancellation;
use App\Monitoring\Engine\MonitorSpec;

/**
 * Adds domain registration facts, such as the expiry date, to the outcome of an http check.
 */
interface DomainFactsProvider
{
    /**
     * Implementations must not block and must not throw for expected failures; the checker ignores anything
     * they throw.
     *
     * @return array{facts: array<string, scalar|list<scalar>|null>, watch: array<string, string>}
     */
    public function inspect(MonitorSpec $spec, string $host, Cancellation $cancellation): array;
}
