<?php

namespace App\Monitoring\Checks\Http;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Socket\TlsInfo;
use App\Monitoring\Net\ProxyConnector;

/**
 * The client and request prepared for a single check. dispose() must be called when the check ends: it closes
 * every connection the check opened and deletes temporary key material.
 */
final class CheckClient
{
    /**
     * @param  list<string>  $temporaryFiles
     */
    public function __construct(
        public readonly HttpClient $http,
        public readonly Request $request,
        private readonly ConnectionTracker $tracker,
        private array $temporaryFiles = [],
        private readonly ?ProxyConnector $proxy = null,
    ) {}

    /**
     * Nanoseconds this check spent on the local policy check of proxy tunnel targets. The proxy resolves the target
     * itself, so that time is not part of the request and is kept out of the measured latency.
     */
    public function guardNanoseconds(): int
    {
        return $this->proxy?->guardNanoseconds() ?? 0;
    }

    /**
     * The TLS session of the final hop, when it used TLS.
     */
    public function tlsInfo(): ?TlsInfo
    {
        return $this->tracker->lastTls();
    }

    public function dispose(): void
    {
        $this->tracker->closeAll();

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->temporaryFiles = [];
    }

    public function __destruct()
    {
        $this->dispose();
    }
}
