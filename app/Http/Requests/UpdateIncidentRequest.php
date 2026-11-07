<?php

namespace App\Http\Requests;

/**
 * Validation of an update to an incident: every field is optional, and the status and first message are set through the timeline instead.
 */
class UpdateIncidentRequest extends IncidentRequest
{
    protected function creating(): bool
    {
        return false;
    }
}
