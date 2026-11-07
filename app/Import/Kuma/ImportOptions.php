<?php

declare(strict_types=1);

namespace App\Import\Kuma;

final readonly class ImportOptions
{
    public function __construct(
        public bool $dryRun = false,
        public bool $history = true,
        public bool $paused = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            dryRun: (bool) ($data['dry_run'] ?? false),
            history: (bool) ($data['history'] ?? true),
            paused: (bool) ($data['paused'] ?? false),
        );
    }

    /**
     * @return array{dry_run: bool, history: bool, paused: bool}
     */
    public function toArray(): array
    {
        return ['dry_run' => $this->dryRun, 'history' => $this->history, 'paused' => $this->paused];
    }
}
