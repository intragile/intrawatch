<?php

namespace App\Status\Pages;

use RuntimeException;
use Throwable;

/**
 * A page could not be rendered because the database failed, and no earlier copy is left to serve.
 */
final class PageUnavailable extends RuntimeException
{
    public static function because(Throwable $previous): self
    {
        return new self('The status page cannot be rendered right now.', 0, $previous);
    }
}
