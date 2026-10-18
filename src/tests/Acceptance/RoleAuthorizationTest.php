<?php

namespace Tests\Acceptance;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Workspaces;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class RoleAuthorizationTest extends AcceptanceCase
{
    public static function roles(): array
    {
        return ['owner' => ['owner', true], 'admin' => ['admin', true],
            'manager' => ['manager', false], 'member' => ['member', false], 'viewer' => ['viewer', false]];
    }

    #[DataProvider('roles')]
    public function test_roles_cannot_forge_tenant_or_actor_fields_or_request_private_props(string $role, bool $canRename): void
    {
        $this->withoutVite();
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $service = app(Workspaces::class);
        $own = $service->create($actor->id, 'Own private workspace');
        $foreign = $service->create($other->id, 'Foreign private workspace');
        $this->control->table('memberships')->where('org_id', $own)->where('user_id', $actor->id)->update(['role' => $role]);
        $this->actingAs($actor)->withSession(['active_org_id' => $own]);
        $this->postJson('/workspace/select', ['org_id' => $foreign])->assertForbidden()->assertSessionHas('active_org_id', $own);
        $response = $this->patchJson('/workspace', [
            'name' => 'Allowed rename', 'org_id' => $foreign, 'actor_id' => $other->id,
            'role' => 'owner', 'revoked_at' => null,
        ]);
        $canRename ? $response->assertOk() : $response->assertForbidden();
        $this->assertSame('Foreign private workspace', $this->control->table('organizations')->where('id', $foreign)->value('name'));
        $this->assertSame($canRename ? 'Allowed rename' : 'Own private workspace', $this->control->table('organizations')->where('id', $own)->value('name'));
        $audit = $this->control->table('audit_logs')->where('org_id', $own)->where('event', 'workspace.renamed')->get();
        $this->assertCount($canRename ? 1 : 0, $audit);
        if ($canRename) {
            $this->assertSame($actor->id, $audit[0]->actor_id);
        }
        $version = app(HandleInertiaRequests::class)->version(request()) ?? '';
        foreach (['Workspaces', 'Auth'] as $component) {
            $this->get('/workspaces', [
                'X-Inertia' => 'true', 'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Component' => $component,
                'X-Inertia-Partial-Data' => 'account,workspaces,user,password,remember_token,memberships,audit_logs',
            ])->assertOk()->assertJsonPath('props.account', $actor->only(['name', 'email']))
                ->assertJsonCount(1, 'props.workspaces')->assertJsonPath('props.workspaces.0.id', $own)
                ->assertJsonPath('props.workspaces.0.role', $role)
                ->assertJsonMissingPath('props.user')->assertJsonMissingPath('props.password')
                ->assertJsonMissingPath('props.remember_token')->assertJsonMissingPath('props.memberships')
                ->assertJsonMissingPath('props.audit_logs')->assertDontSee('Foreign private workspace');
        }
        $this->control->table('memberships')->where('org_id', $own)->where('user_id', $actor->id)->update(['revoked_at' => now()]);
        $this->postJson('/workspace/select', ['org_id' => $own])->assertForbidden();
        $this->patchJson('/workspace', ['name' => 'Revoked write'])->assertForbidden();
        $this->get('/workspaces', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->assertOk()->assertJsonCount(0, 'props.workspaces')->assertJsonPath('props.activeOrgId', null)
            ->assertJsonPath('props.accessRevoked', true)->assertDontSee('Own private workspace')->assertDontSee('Foreign private workspace');
        $this->assertNull(DB::selectOne('SELECT app_actor() AS actor')->actor);
        $this->assertNull(DB::selectOne('SELECT app_org() AS org')->org);
    }
}
