<?php

namespace App\Http\Requests;

/**
 * Validation of an update to a timeline entry: only the message can change.
 */
class UpdateIncidentUpdateRequest extends IncidentUpdateRequest
{
    protected function creating(): bool
    {
        return false;
    }
}
