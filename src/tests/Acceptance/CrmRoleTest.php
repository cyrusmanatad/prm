<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CrmRoleTest extends AcceptanceCase
{
    public function test_all_roles_and_revocation_apply_to_crm_http_and_direct_rls(): void
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'CRM roles');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $data = ['name' => 'Role contact'];
        $id = $this->postJson('/contacts', $data)->assertCreated()->json('id');
        $reminder = $this->postJson('/reminders', ['title' => 'Role reminder', 'due_at' => now()->toIso8601String()])->json('id');
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Role lead', 'value_minor' => '0', 'currency' => 'PHP'])->json('id');
        $version = 1;
        foreach (['owner', 'admin', 'manager', 'member'] as $role) {
            $this->control->table('memberships')->where('org_id', $org)->where('user_id', $user->id)->update(['role' => $role]);
            $this->getJson('/crm')->assertOk()->assertJsonPath('canWrite', true);
            $this->patchJson('/contacts/'.$id, [...$data, 'version' => $version])->assertOk();
            $this->postJson('/reminders/'.$reminder.'/complete', ['version' => $version])->assertOk();
            $this->postJson('/pipeline/'.$lead.'/contacts', ['contact_id' => $id, 'version' => $version++])->assertOk();
        }
        $this->control->table('memberships')->where('org_id', $org)->where('user_id', $user->id)->update(['role' => 'viewer']);
        $this->getJson('/crm')->assertJsonPath('canWrite', false)->assertJsonCount(1, 'contacts.items');
        $this->postJson('/contacts', $data)->assertForbidden();
        $this->patchJson('/contacts/'.$id, [...$data, 'version' => $version])->assertForbidden();
        $this->deleteJson('/contacts/'.$id, ['version' => $version])->assertForbidden();
        $this->postJson('/reminders/'.$reminder.'/snooze', ['version' => $version, 'due_at' => now()->addDay()->toIso8601String()])->assertForbidden();
        $this->postJson('/reminders/'.$reminder.'/reopen', ['version' => $version])->assertForbidden();
        $this->deleteJson('/reminders/'.$reminder, ['version' => $version])->assertForbidden();
        $this->postJson('/pipeline/'.$lead.'/contacts', ['contact_id' => $id, 'version' => $version])->assertForbidden();
        app(TenantContext::class)->run($user->id, $org, function () use ($org, $id): void {
            $this->assertSame(0, DB::table('brand_contacts')->where('id', $id)->update(['name' => 'viewer hacked']));
            $this->assertSame(0, DB::table('brand_contacts')->where('id', $id)->delete());
            $this->assertSame(0, DB::table('reminders')->update(['completed_at' => null]));
            $this->assertSame(0, DB::table('reminders')->delete());
            $this->assertSame(0, DB::table('pipeline_contacts')->delete());
            try {
                DB::transaction(fn () => DB::table('brand_contacts')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'name' => 'viewer', 'notes' => 'forged']));
                $this->fail('Viewer INSERT accepted');
            } catch (QueryException $exception) {
                $this->assertSame('42501', $exception->errorInfo[0]);
            }
        });
        $this->control->table('memberships')->where('org_id', $org)->where('user_id', $user->id)->update(['revoked_at' => now()]);
        $this->getJson('/crm')->assertForbidden();
        $this->patchJson('/contacts/'.$id, [...$data, 'version' => $version])->assertForbidden();
    }
}
