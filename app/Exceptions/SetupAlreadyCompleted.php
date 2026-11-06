<?php

namespace App\Exceptions;

use RuntimeException;

class SetupAlreadyCompleted extends RuntimeException
{
    public static function completed(): self
    {
        return new self('Setup has already been completed.');
    }

    public static function inProgress(): self
    {
        return new self('Setup is already being completed by another request.');
    }
}
