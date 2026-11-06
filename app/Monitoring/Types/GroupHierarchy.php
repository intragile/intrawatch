<?php

namespace App\Monitoring\Types;

use App\Enums\MonitorType;
use App\Models\Monitor;
use Illuminate\Validation\ValidationException;

/**
 * Guards the parent/child relation between monitors: parents are groups of the same workspace, the
 * relation is acyclic and the tree stays shallow.
 */
final class GroupHierarchy
{
    public const MAX_DEPTH = 5;

    /**
     * Detaches the children of a group that is about to be deleted, one row at a time in id order.
     *
     * Leaving this to the ON DELETE SET NULL cascade would lock the children after the group row, the
     * reverse of the child-before-parent order the result recorder uses. Bumping updated_at also lets the
     * check worker notice the new parent.
     */
    public static function detachChildren(Monitor $group): void
    {
        if ($group->type !== MonitorType::Group) {
            return;
        }

        $now = now();
        foreach (Monitor::query()->where('parent_id', $group->id)->orderBy('id')->pluck('id') as $childId) {
            Monitor::query()->whereKey($childId)->toBase()->update(['parent_id' => null, 'updated_at' => $now]);
        }
    }

    /**
     * @throws ValidationException
     */
    public static function assertValidParent(Monitor $child, ?Monitor $parent): void
    {
        if ($parent === null) {
            return;
        }

        // A parent of another workspace is reported exactly like a missing id.
        if ($parent->workspace_id !== $child->workspace_id) {
            self::fail('The selected parent id is invalid.');
        }

        if ($parent->type !== MonitorType::Group) {
            self::fail('The parent must be a group monitor.');
        }

        $above = 0;
        $seen = [];
        $node = $parent;
        while ($node !== null) {
            if ($child->exists && $node->id === $child->id) {
                self::fail('The parent would create a cycle.');
            }
            if (isset($seen[$node->id])) {
                break;
            }
            $seen[$node->id] = true;
            $above++;

            $node = $node->parent_id === null
                ? null
                : Monitor::query()->select(['id', 'workspace_id', 'parent_id', 'type'])->find($node->parent_id);
        }

        if ($above + self::height($child) > self::MAX_DEPTH) {
            self::fail('Groups may be nested at most '.self::MAX_DEPTH.' levels deep.');
        }
    }

    /**
     * Number of levels in the subtree rooted at the monitor, counting the monitor itself.
     */
    private static function height(Monitor $monitor): int
    {
        if (! $monitor->exists) {
            return 1;
        }

        $height = 1;
        $level = [$monitor->id];
        while ($height <= self::MAX_DEPTH) {
            $level = Monitor::query()->whereIn('parent_id', $level)->pluck('id')->all();
            if ($level === []) {
                break;
            }
            $height++;
        }

        return $height;
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['parent_id' => [$message]]);
    }
}
