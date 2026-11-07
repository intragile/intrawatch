<?php

namespace App\Http\Requests;

/**
 * Validation of an update to a status page: every field is optional.
 */
class UpdateStatusPageRequest extends StatusPageRequest
{
    protected function creating(): bool
    {
        return false;
    }
}
