<?php

declare(strict_types=1);

namespace App\Import\Kuma\Mapping;

/**
 * A Kuma status page translated into the page columns, its layout and the domains it was served on.
 */
final readonly class MappedStatusPage
{
    /**
     * @param  array<string, mixed>  $page  Columns of `POST /status-pages`, without the custom domain.
     * @param  array{sections: list<array{name: string, description: null, items: list<array{monitor_id: int}>}>}  $layout  The body of the layout endpoint.
     * @param  list<string>  $domains  Kuma domains in order; the first is the custom domain candidate.
     * @param  list<Note>  $notes
     */
    public function __construct(
        public array $page,
        public array $layout,
        public array $domains,
        public array $notes,
    ) {}
}
