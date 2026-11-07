<?php

namespace App\Alerting\Transport;

use RuntimeException;

/**
 * A failed notification send. The reason comes from a fixed vocabulary; the detail only from provider error
 * fields a sender picks explicitly, so the message never carries request data.
 */
final class DeliveryFailure extends RuntimeException
{
    private const MAX_LENGTH = 500;

    public function __construct(
        public readonly string $reason,
        public readonly bool $permanent = false,
        public readonly ?int $retryAfter = null,
        public readonly ?string $detail = null,
        public readonly ?string $responseBody = null,
    ) {
        parent::__construct(self::compose($reason, $detail));
    }

    public function message(): string
    {
        return $this->getMessage();
    }

    private static function compose(string $reason, ?string $detail): string
    {
        $text = $detail !== null && $detail !== '' ? "{$reason}: {$detail}" : $reason;

        return mb_substr($text, 0, self::MAX_LENGTH);
    }
}
