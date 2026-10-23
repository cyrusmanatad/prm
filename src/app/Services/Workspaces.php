<?php

namespace App\Services;

use App\Enums\WorkspaceRole;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Workspaces
{
    public function __construct(private TenantContext $context, private Audit $audit) {}

    public function listing(int $actorId): array
    {
        return $this->context->run($actorId, null, fn () => DB::table('organizations')
            ->join('memberships', 'memberships.org_id', '=', 'organizations.id')
            ->where('memberships.user_id', $actorId)->whereNull('memberships.revoked_at')
            ->orderBy('organizations.name')->get(['organizations.id', 'organizations.name', 'organizations.kind', 'memberships.role'])->all());
    }

    public function create(int $actorId, string $name, string $kind = 'creator', ?string $provisionId = null): string
    {
        $orgId = $provisionId ?? (string) Str::uuid();
        $this->context->run($actorId, $orgId, function () use ($actorId, $orgId, $name, $kind): void {
            DB::select('SELECT app_create_typed_workspace(?::uuid, ?::text, ?::text)', [$orgId, $name, $kind]);
            $this->audit->record($actorId, $orgId, 'workspace.created', $orgId);
        }, requireMembership: false);

        return $orgId;
    }

    public function select(int $actorId, string $orgId): void
    {
        $this->context->run($actorId, $orgId, fn () => null);
    }

    /** Called within the route's existing tenant transaction. */
    public function rename(int $actorId, string $orgId, string $name): void
    {
        $membership = DB::table('memberships')->where('org_id', $orgId)->where('user_id', $actorId)
            ->whereNull('revoked_at')->first();
        abort_unless($membership && WorkspaceRole::from($membership->role)->canManageSettings(), 403);
        $updated = DB::table('organizations')->where('id', $orgId)->update(['name' => $name, 'updated_at' => now()]);
        abort_unless($updated === 1, 403, 'Workspace settings are no longer available.');
        $this->audit->record($actorId, $orgId, 'workspace.renamed', $orgId);
    }
}
