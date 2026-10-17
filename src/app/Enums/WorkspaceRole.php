<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Member = 'member';
    case Viewer = 'viewer';

    public function canManageSettings(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }
}
