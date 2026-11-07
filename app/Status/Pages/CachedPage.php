<?php

namespace App\Status\Pages;

/**
 * A rendered page as served: the body, its strong entity tag, and whether it is an older copy served because
 * a fresh render failed.
 */
final readonly class CachedPage
{
    public function __construct(
        public string $html,
        public string $etag,
        public bool $stale,
    ) {}
}
