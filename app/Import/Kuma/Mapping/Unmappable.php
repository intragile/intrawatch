<?php

declare(strict_types=1);

namespace App\Import\Kuma\Mapping;

/**
 * A source row that has no equivalent in the target. With field errors it is reported as invalid, without
 * them as skipped.
 */
final readonly class Unmappable
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public Note $reason,
        public array $errors = [],
    ) {}
}
