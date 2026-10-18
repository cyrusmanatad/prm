<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PipelineRoleTest extends AcceptanceCase
{
    public function test_all_roles_and_revocation_apply_to_pipeline_http_and_direct_rls(): void
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'CRM roles');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $data = ['brand_name' => 'Role lead', 'value_minor' => '0', 'currency' => 'PHP'];
        $id = $this->postJson('/pipeline', $data)->assertCreated()->json('id');
        $version = 1;
        foreach (['owner', 'admin', 'manager', 'member'] as $role) {
            $this->control->table('memberships')->where('org_id', $org)->where('user_id', $user->id)->update(['role' => $role]);
            $this->getJson('/pipeline')->assertOk()->assertJsonPath('canWrite', true);
            $this->postJson('/pipeline/'.$id.'/stage', ['stage' => 'pitched', 'version' => $version++])->assertOk();
        }
        $this->control->table('memberships')->where('org_id', $org)->where('user_id', $user->id)->update(['role' => 'viewer']);
        $this->getJson('/pipeline')->assertJsonPath('canWrite', false)->assertJsonCount(1, 'entries');
        $this->postJson('/pipeline', $data)->assertForbidden();
        $this->patchJson('/pipeline/'.$id, [...$data, 'version' => $version])->assertForbidden();
        $this->postJson('/pipeline/'.$id.'/stage', ['stage' => 'active', 'version' => $version])->assertForbidden();
        $this->postJson('/pipeline/'.$id.'/snooze', ['next_action_due' => now()->addDay()->toIso8601String(), 'version' => $version])->assertForbidden();
        $this->postJson('/pipeline/'.$id.'/activities', ['kind' => 'note', 'body' => 'forged', 'version' => $version])->assertForbidden();
        $this->deleteJson('/pipeline/'.$id, ['version' => $version])->assertForbidden();
        app(TenantContext::class)->run($user->id, $org, function () use ($org, $user, $id): void {
            $this->assertSame(0, DB::table('pipeline_entries')->where('id', $id)->update(['brand_name' => 'viewer hacked']));
            $this->assertSame(0, DB::table('pipeline_entries')->where('id', $id)->delete());
            foreach (['pipeline_entries', 'pipeline_activities'] as $table) {
                try {
                    DB::transaction(fn () => DB::table($table)->insert($table === 'pipeline_entries'
                        ? ['id' => (string) Str::uuid(), 'org_id' => $org, 'brand_name' => 'viewer', 'currency' => 'PHP']
                        : ['id' => (string) Str::uuid(), 'org_id' => $org, 'actor_id' => $user->id, 'pipeline_entry_id' => $id, 'kind' => 'note', 'body' => 'viewer']));
                    $this->fail('Viewer INSERT accepted');
                } catch (QueryException $exception) {
                    $this->assertSame('42501', $exception->errorInfo[0]);
                }
            }
        });
        $this->control->table('memberships')->where('org_id', $org)->where('user_id', $user->id)->update(['revoked_at' => now()]);
        $this->getJson('/pipeline')->assertForbidden();
        $this->postJson('/pipeline/'.$id.'/stage', ['stage' => 'active', 'version' => $version])->assertForbidden();
    }
}
