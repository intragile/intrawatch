<?php

namespace App\Monitoring\Checks\Http;

use Amp\Cancellation;
use Amp\Socket\TlsInfo;
use App\Monitoring\Engine\MonitorSpec;

/**
 * Adds certificate facts to the outcome of an https check.
 */
interface TlsFactsProvider
{
    /**
     * Called after a response was received over TLS. Implementations must not block and must not throw for
     * expected failures; the checker ignores anything they throw.
     *
     * @param  TlsInfo  $tls  The negotiated session of the final hop, with the peer certificate chain captured.
     * @return array{facts: array<string, scalar|list<scalar>|null>, watch: array<string, string>}
     */
    public function inspect(MonitorSpec $spec, string $host, TlsInfo $tls, Cancellation $cancellation): array;
}
