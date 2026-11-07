<?php

namespace App\Http\Requests;

/**
 * Validation of an update to a maintenance: every field is optional; the ones left out keep their stored value.
 */
class UpdateMaintenanceRequest extends MaintenanceRequest
{
    protected function creating(): bool
    {
        return false;
    }
}
