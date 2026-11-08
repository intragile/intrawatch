<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An import request that conflicts with the current state of an import. Rendered as a 409 problem whose type
 * tells the cases apart.
 */
class ImportConflict extends RuntimeException
{
    private function __construct(public readonly string $type, string $message)
    {
        parent::__construct($message);
    }

    public static function inProgress(): self
    {
        return new self('urn:problem:import-in-progress', 'Another import of this workspace has not finished yet.');
    }

    public static function busy(): self
    {
        return new self('urn:problem:import-in-progress', 'The import is being processed; wait until it has finished.');
    }

    public static function notAnalyzed(): self
    {
        return new self('urn:problem:import-not-analyzed', 'Only an analysed import can be run.');
    }
}
