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
        $own = $this->postJson('/contacts', ['name' => 'Own'])->json('id');
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Own lead', 'value_minor' => '0', 'currency' => 'PHP'])->json('id');
        $this->patchJson('/contacts/'.$foreign, ['name' => 'hack', 'version' => 1])->assertNotFound();
        $this->deleteJson('/contacts/'.$foreign, ['version' => 1])->assertNotFound();
        $this->postJson('/pipeline/'.$lead.'/contacts', ['contact_id' => $foreign, 'version' => 1])->assertNotFound();
        $this->getJson('/crm')->assertDontSee('Foreign private')->assertJsonMissingPath('contacts.items.0.org_id')->assertJsonPath('summary.contacts', 1);
        $this->postJson('/contacts/bulk', ['action' => 'delete', 'items' => [['id' => $own, 'version' => 1], ['id' => $foreign, 'version' => 1]]])->assertNotFound();
        $this->postJson('/contacts/bulk', ['action' => 'link', 'items' => [['id' => $foreign, 'version' => 1]], 'link_entry' => $lead, 'entry_version' => 1])->assertNotFound();
        $this->get('/crm/exports/contacts?ids[]='.$foreign)->assertOk()->assertDontSee('Foreign private');
        $this->get('/crm/exports/reminders')->assertOk()->assertDontSee('Foreign task');
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

    public function test_numbered_pages_sorting_link_filter_and_summary(): void
    {
        [$u,$o] = $this->workspace();
        $ids = [];
        foreach (['Cara', 'abe', 'Bea'] as $index => $name) {
            $ids[$name] = $this->postJson('/contacts', ['name' => $name, 'company' => $index === 1 ? '' : 'Co '.$name])->json('id');
        }
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Paged lead', 'value_minor' => '0', 'currency' => 'PHP'])->json('id');
        $this->postJson('/pipeline/'.$lead.'/contacts', ['contact_id' => $ids['Bea'], 'version' => 1])->assertOk();

        $this->getJson('/crm?sort=name_asc&per_page=10')->assertJsonPath('contacts.items.0.name', 'abe')->assertJsonPath('contacts.items.2.name', 'Cara')
            ->assertJsonPath('contacts.total', 3)->assertJsonPath('contacts.perPage', 10)->assertJsonPath('contacts.page', 1)
            ->assertJsonPath('filters.sort', 'name_asc')->assertJsonPath('filters.per_page', '10');
        $this->getJson('/crm?sort=name_desc')->assertJsonPath('contacts.items.0.name', 'Cara');
        $this->getJson('/crm?sort=company_asc')->assertJsonPath('contacts.items.0.name', 'Bea')->assertJsonPath('contacts.items.2.name', 'abe');
        $this->getJson('/crm?linked=linked')->assertJsonCount(1, 'contacts.items')->assertJsonPath('contacts.items.0.lead_count', 1);
        $this->getJson('/crm?linked=unlinked')->assertJsonCount(2, 'contacts.items')->assertJsonPath('contacts.items.0.lead_count', 0);
        $this->getJson('/crm?per_page=7')->assertUnprocessable();
        $this->getJson('/crm?sort=forged')->assertUnprocessable();

        for ($i = 0; $i < 11; $i++) {
            $this->postJson('/contacts', ['name' => 'Bulk '.$i]);
        }
        $this->getJson('/crm?per_page=10&contacts_page=2')->assertJsonCount(4, 'contacts.items')->assertJsonPath('contacts.page', 2)->assertJsonPath('contacts.total', 14);
        $this->getJson('/crm?per_page=10&contacts_page=99')->assertJsonPath('contacts.page', 2);

        $overdue = $this->postJson('/reminders', ['title' => 'Overdue', 'due_at' => now()->subHour()->toIso8601String(), 'pipeline_entry_id' => $lead])->json('id');
        $this->postJson('/reminders', ['title' => 'Soon', 'due_at' => now()->addHours(3)->toIso8601String()]);
        $this->postJson('/reminders', ['title' => 'Later', 'due_at' => now()->addDays(3)->toIso8601String()]);
        $this->postJson('/reminders', ['title' => 'Far', 'due_at' => now()->addDays(30)->toIso8601String()]);
        $done = $this->postJson('/reminders', ['title' => 'Done', 'due_at' => now()->subDays(2)->toIso8601String()])->json('id');
        $this->postJson('/reminders/'.$done.'/complete', ['version' => 1])->assertOk();
        $this->getJson('/crm')->assertJsonPath('reminders.items.0.title', 'Overdue')->assertJsonPath('reminders.items.0.lead_name', 'Paged lead')
            ->assertJsonPath('reminders.items.3.title', 'Far')->assertJsonPath('reminders.total', 4)
            ->assertJsonPath('summary', ['contacts' => 14, 'overdue' => 1, 'dueDay' => 1, 'dueWeek' => 2]);
        $this->getJson('/crm?state=all')->assertJsonPath('reminders.items.4.title', 'Done');
        $this->post('/reminders/'.$overdue.'/complete', ['version' => 1, 'crm_filters' => ['q' => '', 'state' => 'all', 'sort' => 'name_asc', 'linked' => '', 'per_page' => '10', 'contacts_page' => '2', 'reminders_page' => '']])
            ->assertRedirect('/partner/contacts?state=all&sort=name_asc&per_page=10&contacts_page=2');
    }

    public function test_bulk_link_and_delete_are_all_or_nothing_and_exports_are_safe(): void
    {
        [$u,$o] = $this->workspace();
        $a = $this->postJson('/contacts', ['name' => 'Ana', 'email' => 'ana@example.test', 'notes' => '=HYPERLINK("x")'])->json('id');
        $b = $this->postJson('/contacts', ['name' => 'Ben'])->json('id');
        $c = $this->postJson('/contacts', ['name' => 'Cy'])->json('id');
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Bulk lead', 'value_minor' => '0', 'currency' => 'PHP'])->json('id');

        $this->postJson('/contacts/bulk', ['action' => 'link', 'items' => [['id' => $a, 'version' => 1], ['id' => $b, 'version' => 1]], 'link_entry' => $lead, 'entry_version' => 2])->assertConflict();
        $this->postJson('/contacts/bulk', ['action' => 'link', 'items' => [['id' => $a, 'version' => 1], ['id' => $b, 'version' => 2]], 'link_entry' => $lead, 'entry_version' => 1])->assertConflict();
        $this->getJson('/crm?entry='.$lead)->assertJsonCount(0, 'linked')->assertJsonPath('entry.version', 1);
        $this->postJson('/contacts/bulk', ['action' => 'link', 'items' => [['id' => $a, 'version' => 1], ['id' => $b, 'version' => 1]], 'link_entry' => $lead, 'entry_version' => 1])
            ->assertOk()->assertJsonPath('message', '2 contacts linked');
        $this->getJson('/crm?entry='.$lead)->assertJsonCount(2, 'linked')->assertJsonPath('entry.version', 2);

        $this->postJson('/contacts/bulk', ['action' => 'delete', 'items' => [['id' => $b, 'version' => 1], ['id' => $c, 'version' => 1]]])
            ->assertConflict()->assertJsonPath('errors.contact.0', '1 selected contact is linked to a lead. Unlink it before deleting.');
        $this->getJson('/crm')->assertJsonPath('contacts.total', 3);
        $this->postJson('/contacts/bulk', ['action' => 'delete', 'items' => [['id' => $c, 'version' => 1], ['id' => $c, 'version' => 1]]])->assertUnprocessable();
        $this->postJson('/contacts/bulk', ['action' => 'delete', 'items' => []])->assertUnprocessable();
        $this->postJson('/contacts/bulk', ['action' => 'archive', 'items' => [['id' => $c, 'version' => 1]]])->assertUnprocessable();
        $this->postJson('/contacts/bulk', ['action' => 'delete', 'items' => [['id' => $c, 'version' => 1]]])->assertOk()->assertJsonPath('message', '1 contact deleted');
        $this->getJson('/crm')->assertJsonPath('contacts.total', 2);

        $response = $this->get('/crm/exports/contacts?sort=name_asc')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $csv = $response->getContent();
        $lines = explode("\n", trim($csv));
        $this->assertSame('id,name,email,company,notes,linked_leads', $lines[0]);
        $this->assertStringContainsString(",Ana,ana@example.test,,\"'=HYPERLINK(\"\"x\"\")\",1", $lines[1]);
        $this->assertCount(3, $lines);
        $selected = $this->get('/crm/exports/contacts?ids[]='.$b)->getContent();
        $this->assertStringNotContainsString('Ana', $selected);
        $this->assertStringContainsString('Ben', $selected);
        $this->postJson('/reminders', ['title' => 'Export me', 'due_at' => now()->addDay()->toIso8601String(), 'pipeline_entry_id' => $lead]);
        $this->assertStringContainsString('Export me', $this->get('/crm/exports/reminders?state=pending')->getContent());
        $this->assertStringNotContainsString('Export me', $this->get('/crm/exports/reminders?state=completed')->getContent());
        $this->get('/crm/exports/leads')->assertNotFound();
        app(TenantContext::class)->run($u->id, $o, fn () => $this->assertSame(4, DB::table('audit_logs')->whereIn('event', ['contacts.exported', 'reminders.exported'])->count()));
    }
}
