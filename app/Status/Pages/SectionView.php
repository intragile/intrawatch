<?php

namespace App\Status\Pages;

/**
 * A titled group of components.
 */
final readonly class SectionView
{
    /**
     * @param  list<ItemView>  $items
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public array $items,
    ) {}
}
