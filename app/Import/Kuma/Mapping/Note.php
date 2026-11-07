<?php

declare(strict_types=1);

namespace App\Import\Kuma\Mapping;

/**
 * A remark attached to a report entry or to the whole run. The code is stable; the message is English prose.
 */
final readonly class Note
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}

    public static function of(string $code, string $message): self
    {
        return new self($code, $message);
    }

    /**
     * @return array{code: string, message: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message];
    }
}
