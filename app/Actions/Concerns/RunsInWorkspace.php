<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Container\Container;

/**
 * For actions that run a form request outside an HTTP request: its rules scope their existence checks to the
 * current workspace, which the workspace middleware sets for a real request.
 */
trait RunsInWorkspace
{
    /**
     * Runs the callback with the given workspace as the current one. Only the workspace matters to the
     * validation rules, the role is never consulted.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function inWorkspace(Workspace $workspace, Closure $callback): mixed
    {
        $container = Container::getInstance();
        $current = $container->make(CurrentWorkspace::class);
        if ($current->isResolved() && $current->get()->is($workspace)) {
            return $callback();
        }

        $scoped = new CurrentWorkspace;
        $scoped->set($workspace, WorkspaceRole::Owner);
        $container->instance(CurrentWorkspace::class, $scoped);
        try {
            return $callback();
        } finally {
            $container->instance(CurrentWorkspace::class, $current);
        }
    }
}
