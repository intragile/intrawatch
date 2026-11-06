<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    /**
     * Privilege rank; a higher number means more privileges.
     */
    private function rank(): int
    {
        return match ($this) {
            self::Owner => 4,
            self::Admin => 3,
            self::Editor => 2,
            self::Viewer => 1,
        };
    }

    public function atLeast(self $min): bool
    {
        return $this->rank() >= $min->rank();
    }
}
