<?php

namespace App\Monitoring\Checks\Http;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Socket\TlsInfo;

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
    ) {}

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
