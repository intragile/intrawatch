<?php

declare(strict_types=1);

namespace App\Import\Kuma\Steps;

use App\Import\Kuma\ImportContext;

/**
 * One stage of the import (tags, notifications, monitors, ...). A step reads from the context's source and
 * reports every source row it handles; it must not depend on the internals of other steps.
 */
interface ImportStep
{
    public function run(ImportContext $ctx): void;
}
