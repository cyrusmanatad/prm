<?php

namespace Tests\Integration;

use App\Models\User;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmTest extends TestCase
{
    private function workspace(): array
    {
        $u = User::factory()->create();
        $o = app(Workspaces::class)->create($u->id, 'CRM test');
        $this->actingAs($u)->withSession(['active_org_id' => $o]);

        return [$u, $o];
    }

    public function test_reusable_contacts_link_to_multiple_leads_and_reminders_snooze_complete_reopen(): void
    {
        [$u,$o] = $this->workspace();
        $contact = $this->postJson('/contacts', ['name' => 'Sam', 'email' => 'sam@example.test', 'notes' => 'Private contact note', 'org_id' => (string) Str::uuid()])->assertCreated()->json('id');
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Studio', 'value_minor' => '0', 'currency' => 'PHP'])->json('id');
        $lead2 = $this->postJson('/pipeline', ['brand_name' => 'Studio two', 'value_minor' => '0', 'currency' => 'PHP'])->json('id');
        foreach ([$lead, $lead2] as $id) {
            $this->postJson('/pipeline/'.$id.'/contacts', ['contact_id' => $contact, 'version' => 1])->assertOk();
        }
        $this->getJson('/crm?entry='.$lead)->assertJsonPath('linked.0.name', 'Sam')->assertJsonPath('entry.version', 2);
        $this->patchJson('/contacts/'.$contact, ['name' => 'Samantha', 'version' => 1])->assertOk();
        $this->getJson('/crm?entry='.$lead2)->assertJsonPath('linked.0.name', 'Samantha');
        $this->deleteJson('/contacts/'.$contact, ['version' => 2])->assertConflict();
        $this->postJson('/pipeline/'.$lead.'/contacts', ['contact_id' => $contact, 'version' => 1])->assertConflict();
        $id = $this->postJson('/reminders', ['title' => 'Follow up', 'due_at' => now()->subDay()->toIso8601String(), 'pipeline_entry_id' => $lead])->assertCreated()->json('id');
        $this->getJson('/crm?state=due')->assertJsonCount(1, 'reminders.items');
        $this->postJson('/reminders/'.$id.'/snooze', ['version' => 1, 'due_at' => now()->addDays(3)->toIso8601String()])->assertOk();
        $this->getJson('/crm?state=due')->assertJsonCount(0, 'reminders.items');
        $this->postJson('/reminders/'.$id.'/complete', ['version' => 1])->assertConflict();
        $this->postJson('/reminders/'.$id.'/complete', ['version' => 2])->assertOk();
        $this->getJson('/crm?state=completed')->assertJsonCount(1, 'reminders.items');
        $this->postJson('/reminders/'.$id.'/reopen', ['version' => 3])->assertOk();
        $this->getJson('/crm')->assertJsonPath('reminders.items.0.completed_at', null);
        foreach ([$lead, $lead2] as $entry) {
            $this->deleteJson('/pipeline/'.$entry.'/contacts', ['contact_id' => $contact, 'version' => 2])->assertOk();
        }
        $this->deleteJson('/contacts/'.$contact, ['version' => 2])->assertOk();
        $this->deleteJson('/reminders/'.$id, ['version' => 4])->assertOk();
        app(TenantContext::class)->run($u->id, $o, fn () => $this->assertStringNotContainsString('Private contact note', json_encode(DB::table('audit_logs')->get())));
    }

    public function test_browser_mutations_preserve_validated_filters_and_reset_paging(): void
    {
        $this->workspace();
        $filters = ['q' => '0', 'state' => 'completed'];
        $this->post('/contacts', ['name' => 'Filtered contact', 'crm_filters' => $filters])
            ->assertRedirect('/partner/contacts?q=0&state=completed');
        $id = $this->postJson('/reminders', ['title' => 'Complete me', 'due_at' => now()->toIso8601String()])->json('id');
        $this->post('/reminders/'.$id.'/complete', ['version' => 1, 'crm_filters' => $filters])
            ->assertRedirect('/partner/contacts?q=0&state=completed');
        $this->getJson('/crm?state=completed')->assertJsonPath('reminders.items.0.id', $id);
        $this->postJson('/reminders/'.$id.'/reopen', ['version' => 2, 'crm_filters' => ['state' => 'forged']])->assertUnprocessable();
        $this->getJson('/crm?state=completed')->assertJsonPath('reminders.items.0.version', 2);
        $this->postJson('/contacts', ['name' => 'Rejected context', 'crm_filters' => ['q' => str_repeat('a', 161)]])->assertUnprocessable();
        $this->getJson('/crm')->assertJsonCount(1, 'contacts.items');
    }

    public function test_foreign_ids_all_rls_operations_and_same_owner_relations(): void
    {
        [$u,$o] = $this->workspace();
        $other = User::factory()->create();
        $foreignOrg = app(Workspaces::class)->create($other->id, 'Other CRM');
        $foreign = (string) Str::uuid();
        app(TenantContext::class)->run($other->id, $foreignOrg, fn () => DB::table('brand_contacts')->insert(['id' => $foreign, 'org_id' => $foreignOrg, 'name' => 'Foreign private']));
        $foreignReminder = (string) Str::uuid();
        app(TenantContext::class)->run($other->id, $foreignOrg, fn () => DB::table('reminders')->insert(['id' => $foreignReminder, 'org_id' => $foreignOrg, 'title' => 'Foreign task', 'due_at' => now()]));
        $this->patchJson('/reminders/'.$foreignReminder, ['title' => 'hack', 'due_at' => now()->toIso8601String(), 'version' => 1])->assertNotFound();
        foreach (['complete', 'reopen', 'snooze'] as $action) {
            $this->postJson('/reminders/'.$foreignReminder.'/'.$action, ['version' => 1, 'due_at' => now()->addDay()->toIso8601String()])->assertNotFound();
        }
        $this->deleteJson('/reminders/'.$foreignReminder, ['version' => 1])->assertNotFound();
        $this->getJson('/crm?reminders_cursor='.$foreignReminder)->assertNotFound();
        $own = $this->postJson('/contacts', ['name' => 'Own'])->json('id');
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Own lead', 'value_minor' => '0', 'currency' => 'PHP'])->json('id');
        $this->patchJson('/contacts/'.$foreign, ['name' => 'hack', 'version' => 1])->assertNotFound();
        $this->deleteJson('/contacts/'.$foreign, ['version' => 1])->assertNotFound();
        $this->postJson('/pipeline/'.$lead.'/contacts', ['contact_id' => $foreign, 'version' => 1])->assertNotFound();
        $this->getJson('/crm')->assertDontSee('Foreign private')->assertJsonMissingPath('contacts.items.0.org_id');
        app(TenantContext::class)->run($u->id, $o, function () use ($o, $foreignOrg, $foreign, $foreignReminder, $lead): void {
            $this->assertSame(0, DB::table('brand_contacts')->where('id', $foreign)->count());
            $this->assertSame(0, DB::table('brand_contacts')->where('id', $foreign)->update(['name' => 'hack']));
            $this->assertSame(0, DB::table('brand_contacts')->where('id', $foreign)->delete());
            $this->assertSame(0, DB::table('reminders')->where('id', $foreignReminder)->count());
            $this->assertSame(0, DB::table('reminders')->where('id', $foreignReminder)->update(['title' => 'hack']));
            $this->assertSame(0, DB::table('reminders')->where('id', $foreignReminder)->delete());

            foreach (['brand_contacts', 'reminders', 'pipeline_contacts'] as $table) {
                try {
                    DB::transaction(fn () => DB::table($table)->insert(match ($table) {
                        'brand_contacts' => ['id' => (string) Str::uuid(), 'org_id' => $foreignOrg, 'name' => 'hack'],
                        'reminders' => ['id' => (string) Str::uuid(), 'org_id' => $foreignOrg, 'title' => 'hack', 'due_at' => now()],
                        'pipeline_contacts' => ['org_id' => $o, 'pipeline_entry_id' => $lead, 'contact_id' => $foreign],
                    }));
                    $this->fail('Forged write accepted');
                } catch (QueryException $e) {
                    $this->assertSame($table === 'pipeline_contacts' ? '23503' : '42501', $e->errorInfo[0]);
                }
            }
        });
        $this->postJson('/reminders', ['title' => 'Bad', 'due_at' => '2026-01-01T10:00'])->assertUnprocessable();
        $this->postJson('/reminders', ['title' => 'Bad', 'due_at' => now()->toIso8601String(), 'pipeline_entry_id' => (string) Str::uuid()])->assertNotFound();
    }
}
