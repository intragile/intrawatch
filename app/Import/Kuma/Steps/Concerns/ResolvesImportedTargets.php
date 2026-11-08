<?php

declare(strict_types=1);

namespace App\Import\Kuma\Steps\Concerns;

use App\Import\Kuma\ImportContext;
use App\Import\Kuma\Steps\ImportMonitors;
use App\Import\Kuma\Steps\ImportStatusPages;
use App\Models\Monitor;
use App\Models\StatusPage;

/**
 * Resolves the Kuma monitors and status pages a later step refers to: only those the import created or
 * found, and only while they still exist. In dry-run the placeholder ids of the entities that would be
 * created resolve as they are.
 */
trait ResolvesImportedTargets
{
    /** @var array<int, bool> */
    private array $monitorExists = [];

    /** @var array<int, bool> */
    private array $pageExists = [];

    private function resolveMonitor(ImportContext $ctx, int $kumaId): ?int
    {
        $id = $ctx->mappings->targetId(ImportMonitors::ENTITY, $kumaId);
        if ($id === null || $id < 0) {
            return $id;
        }

        $this->monitorExists[$id] ??= Monitor::query()->forWorkspace($ctx->workspace)->whereKey($id)->exists();

        return $this->monitorExists[$id] ? $id : null;
    }

    private function resolvePage(ImportContext $ctx, int $kumaId): ?int
    {
        $id = $ctx->mappings->targetId(ImportStatusPages::ENTITY, $kumaId);
        if ($id === null || $id < 0) {
            return $id;
        }

        $this->pageExists[$id] ??= StatusPage::query()->forWorkspace($ctx->workspace)->whereKey($id)->exists();

        return $this->pageExists[$id] ? $id : null;
    }
}
