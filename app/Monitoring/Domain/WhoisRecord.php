<?php

namespace App\Monitoring\Domain;

use Carbon\CarbonImmutable;

/**
 * What a WHOIS answer says about a domain.
 */
final readonly class WhoisRecord
{
    /**
     * @param  bool  $notFound  The server stated that the domain is not registered.
     * @param  bool  $throttled  The server refused the query because of a rate limit.
     */
    public function __construct(
        public ?CarbonImmutable $expiresAt,
        public ?string $registrar,
        public bool $notFound,
        public bool $throttled,
    ) {}
}
