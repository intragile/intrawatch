<?php

namespace App\Alerting\Messages;

/**
 * What a sender delivers: a single alert or a digest of several.
 */
final readonly class OutgoingNotification
{
    /**
     * @param  list<AlertMessage>  $items
     */
    public function __construct(
        public string $title,
        public string $body,
        public string $severity,
        public array $items,
        public bool $digest,
    ) {}
}
