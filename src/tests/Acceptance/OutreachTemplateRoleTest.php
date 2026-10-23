<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutreachTemplateRoleTest extends AcceptanceCase
{
    public function test_all_roles_and_revocation_apply_to_template_http_and_direct_rls(): void
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'CRM roles');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $data = ['name' => 'Role template', 'subject' => '', 'body' => 'Private pitch'];
        $id = $this->postJson('/outreach-templates', $data)->assertCreated()->json('id');
        $version = 1;
        foreach (['owner', 'admin', 'manager', 'member'] as $role) {
            $this->control->table('memberships')->where('org_id', $org)->where('user_id', $user->id)->update(['role' => $role]);
            $this->getJson('/outreach-templates')->assertOk()->assertJsonPath('canWrite', true);
            $this->patchJson('/outreach-templates/'.$id, [...$data, 'version' => $version++])->assertOk();
        }
        $this->control->table('memberships')->where('org_id', $org)->where('user_id', $user->id)->update(['role' => 'viewer']);
        $this->getJson('/outreach-templates')->assertJsonPath('canWrite', false)->assertJsonCount(1, 'templates');
        $this->postJson('/outreach-templates', $data)->assertForbidden();
        $this->patchJson('/outreach-templates/'.$id, [...$data, 'version' => $version])->assertForbidden();
        $this->deleteJson('/outreach-templates/'.$id, ['version' => $version])->assertForbidden();
        app(TenantContext::class)->run($user->id, $org, function () use ($org, $id): void {
            $this->assertSame(0, DB::table('outreach_templates')->where('id', $id)->update(['name' => 'viewer hacked']));
            $this->assertSame(0, DB::table('outreach_templates')->where('id', $id)->delete());
            try {
                DB::transaction(fn () => DB::table('outreach_templates')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'name' => 'viewer', 'body' => 'forged']));
                $this->fail('Viewer INSERT accepted');
            } catch (QueryException $exception) {
                $this->assertSame('42501', $exception->errorInfo[0]);
            }
        });
        $this->control->table('memberships')->where('org_id', $org)->where('user_id', $user->id)->update(['revoked_at' => now()]);
        $this->getJson('/outreach-templates')->assertForbidden();
        $this->patchJson('/outreach-templates/'.$id, [...$data, 'version' => $version])->assertForbidden();
    }
}
