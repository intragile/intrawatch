<?php

namespace App\Alerting\Transport;

final readonly class OutboundResponse
{
    /**
     * @param  array<array<string>>  $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    /**
     * @return array<array-key, mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
