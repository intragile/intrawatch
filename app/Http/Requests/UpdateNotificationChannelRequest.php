<?php

namespace App\Http\Requests;

/**
 * Validation of a notification channel update: every field is optional and the type cannot change.
 */
class UpdateNotificationChannelRequest extends NotificationChannelRequest
{
    protected function creating(): bool
    {
        return false;
    }
}
