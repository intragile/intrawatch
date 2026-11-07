<?php

declare(strict_types=1);

namespace App\Import\Kuma\Mapping;

/**
 * A source row translated into attributes of the target entity, with the remarks the translation produced.
 */
final readonly class Mapped
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<Note>  $notes
     */
    public function __construct(
        public array $attributes,
        public array $notes = [],
    ) {}
}
