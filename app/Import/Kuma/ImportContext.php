<?php

declare(strict_types=1);

namespace App\Import\Kuma;

use App\Import\Kuma\Source\KumaSource;
use App\Models\User;
use App\Models\Workspace;

/**
 * Everything an import step needs. `actor` is null when the import runs as the system (CLI).
 */
final readonly class ImportContext
{
    public function __construct(
        public KumaSource $source,
        public Workspace $workspace,
        public ?User $actor,
        public ImportOptions $options,
        public MappingStore $mappings,
        public ImportReport $report,
    ) {}
}
