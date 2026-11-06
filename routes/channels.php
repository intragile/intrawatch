<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;

// Live monitor updates of one workspace. A token bound to a workspace may only listen to that workspace.
Broadcast::channel('workspace.{id}', function (User $user, string $id): bool {
    if (! ctype_digit($id)) {
        return false;
    }

    $scoped = $user->scopedWorkspaceId();
    if ($scoped !== null && $scoped !== (int) $id) {
        return false;
    }

    $workspace = Workspace::find((int) $id);

    return $workspace !== null && $user->roleIn($workspace) !== null;
});
