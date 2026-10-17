<?php

namespace Tests\Unit;

use App\Enums\WorkspaceRole;
use PHPUnit\Framework\TestCase;

class WorkspaceRoleTest extends TestCase
{
    public function test_only_owners_and_admins_can_manage_settings(): void
    {
        foreach (WorkspaceRole::cases() as $role) {
            $this->assertSame(in_array($role, [WorkspaceRole::Owner, WorkspaceRole::Admin], true), $role->canManageSettings());
        }
    }
}
