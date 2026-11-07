<?php

namespace App\Enums;

/**
 * What happened to one source row.
 */
enum ImportOutcome: string
{
    /** Created in the workspace. */
    case Created = 'created';

    /** Dry-run only: would be created by a real run. */
    case WouldCreate = 'would_create';

    /** Already imported earlier, or a local entity was reused; nothing was written. */
    case Existing = 'existing';

    /** Cannot exist in IntraWatch. */
    case Skipped = 'skipped';

    /** Rejected by validation; the report entry carries the field errors. */
    case Invalid = 'invalid';

    /** An unexpected error; the details are in the application log. */
    case Failed = 'failed';
}
