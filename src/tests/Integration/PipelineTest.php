<?php

namespace Tests\Integration;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Pipeline;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    private function lead(array $extra = []): array
    {
        return [...['brand_name' => 'Private brand', 'contact_name' => 'Creator contact', 'contact_email' => 'contact@example.test',
            'notes' => 'Never share with a brand', 'value_minor' => '450050', 'currency' => 'PHP', 'next_action_due' => null], ...$extra];
    }

    private function workspace(): array
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Pipeline test');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);

        return [$user, $org];
    }

    public function test_capture_edit_seven_manual_stages_snooze_activity_and_delete_persist(): void
    {
        [$actor, $org] = $this->workspace();
        $id = $this->postJson('/pipeline', $this->lead(['org_id' => (string) Str::uuid(), 'stage' => 'active', 'version' => 99, 'actor_id' => 999]))
            ->assertCreated()->json('id');
        $this->getJson('/pipeline')->assertOk()->assertJsonPath('entries.0.stage', 'lead')->assertJsonPath('entries.0.version', 1)
            ->assertJsonMissingPath('entries.0.notes')->assertJsonPath('totals.0.amount_minor', '450050');
        $this->getJson('/pipeline?entry='.$id)->assertJsonPath('selected.notes', 'Never share with a brand')
            ->assertJsonPath('selected.activities.0.kind', 'created');
        $this->patchJson('/pipeline/'.$id, [...$this->lead(['brand_name' => 'Changed']), 'version' => 1])->assertOk();
        $version = 2;
        foreach (['pitched', 'negotiating', 'onboarded', 'active', 'repeat-client', 'lost', 'lead'] as $stage) {
            $this->postJson('/pipeline/'.$id.'/stage', ['stage' => $stage, 'version' => $version++])->assertOk();
            $this->getJson('/pipeline?entry='.$id)->assertJsonPath('selected.stage', $stage)->assertJsonPath('selected.version', $version);
        }
        $this->postJson('/pipeline/'.$id.'/stage', ['stage' => 'won', 'version' => $version])->assertUnprocessable();
        $this->postJson('/pipeline/'.$id.'/snooze', ['next_action_due' => now()->subDay()->toIso8601String(), 'version' => $version])->assertUnprocessable();
        $due = now()->addDays(3)->startOfMinute()->toIso8601String();
        $this->postJson('/pipeline/'.$id.'/snooze', ['next_action_due' => $due, 'version' => $version++])->assertOk();
        $this->getJson('/pipeline?due=1')->assertJsonCount(0, 'entries');
        $this->postJson('/pipeline/'.$id.'/activities', ['kind' => 'email', 'body' => 'Manually logged conversation.', 'version' => $version++])->assertCreated();
        $this->getJson('/pipeline?entry='.$id)->assertJsonPath('selected.next_action_due', $due)
            ->assertJsonPath('selected.activities.0.body', 'Manually logged conversation.');
        app(TenantContext::class)->run($actor->id, $org, function () use ($actor, $id): void {
            $this->assertSame($actor->id, DB::table('pipeline_activities')->where('pipeline_entry_id', $id)->value('actor_id'));
            $this->assertStringNotContainsString('Never share', json_encode(DB::table('audit_logs')->get()));
        });
        $this->deleteJson('/pipeline/'.$id, ['version' => $version])->assertOk();
        $this->getJson('/pipeline?entry='.$id)->assertNotFound();
        $this->assertNull(DB::selectOne('SELECT app_org() AS org')->org);
    }

    public function test_stale_commands_reject_without_overwriting_or_appending_activity_and_web_errors_redirect(): void
    {
        [$actor, $org] = $this->workspace();
        $id = $this->postJson('/pipeline', $this->lead())->json('id');
        $this->postJson('/pipeline/'.$id.'/stage', ['stage' => 'pitched', 'version' => 1])->assertOk();
        $this->patchJson('/pipeline/'.$id, [...$this->lead(['brand_name' => 'Stale overwrite']), 'version' => 1])->assertConflict()->assertJsonValidationErrors('version');
        $this->postJson('/pipeline/'.$id.'/activities', ['kind' => 'note', 'body' => 'Stale note', 'version' => 1])->assertConflict();
        $this->postJson('/pipeline/'.$id.'/snooze', ['next_action_due' => now()->addDay()->toIso8601String(), 'version' => 1])->assertConflict();
        $this->deleteJson('/pipeline/'.$id, ['version' => 1])->assertConflict();
        $this->from('/pipeline?entry='.$id)->post('/pipeline/'.$id.'/stage', ['stage' => 'active', 'version' => 1], ['X-Inertia' => 'true'])
            ->assertStatus(302)->assertRedirect('/pipeline?entry='.$id)->assertSessionHasErrors('version');
        $detail = $this->getJson('/pipeline?entry='.$id)->assertJsonPath('selected.brand_name', 'Private brand')->assertJsonPath('selected.stage', 'pitched');
        $this->assertCount(2, $detail->json('selected.activities'));
        app(TenantContext::class)->run($actor->id, $org, fn () => $this->assertSame(2, DB::table('audit_logs')->where('subject_id', $id)->count()));
    }

    public function test_cross_workspace_ids_cursor_partial_props_and_forged_fields_never_disclose_private_data(): void
    {
        [$actor, $org] = $this->workspace();
        $other = User::factory()->create();
        $foreignOrg = app(Workspaces::class)->create($other->id, 'Hidden tenant');
        $foreign = app(TenantContext::class)->run($other->id, $foreignOrg, fn () => app(Pipeline::class)->create($other->id, $foreignOrg, $this->lead(['brand_name' => 'Hidden brand'])));
        $own = $this->postJson('/pipeline', $this->lead())->json('id');
        $this->getJson('/pipeline?entry='.$foreign)->assertNotFound();
        $this->getJson('/pipeline?cursor='.$foreign)->assertNotFound();
        $this->patchJson('/pipeline/'.$foreign, [...$this->lead(), 'version' => 1])->assertNotFound();
        $this->postJson('/pipeline/'.$foreign.'/stage', ['stage' => 'active', 'version' => 1])->assertNotFound();
        $this->postJson('/pipeline/'.$foreign.'/snooze', ['next_action_due' => now()->addDay()->toIso8601String(), 'version' => 1])->assertNotFound();
        $this->postJson('/pipeline/'.$foreign.'/activities', ['body' => 'forged', 'kind' => 'note', 'version' => 1])->assertNotFound();
        $this->deleteJson('/pipeline/'.$foreign, ['version' => 1])->assertNotFound();
        $this->withoutVite();
        $this->get('/pipeline?entry='.$own)->assertInertia(fn (Assert $page) => $page->component('Pipeline')
            ->where('workspace.id', $org)->where('entries.0.id', $own)->where('selected.notes', 'Never share with a brand')->etc());
        $this->get('/pipeline', ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '',
            'X-Inertia-Partial-Component' => 'Pipeline', 'X-Inertia-Partial-Data' => 'entries,notes,actor_id,org_id'])
            ->assertOk()->assertJsonCount(1, 'props.entries')->assertJsonMissingPath('props.notes')->assertJsonMissingPath('props.actor_id')->assertDontSee('Hidden brand');
        $this->withSession(['active_org_id' => $foreignOrg])->getJson('/pipeline')->assertForbidden();
    }

    public function test_currency_totals_are_exact_filtered_and_independent_of_cursor_page(): void
    {
        [$actor, $org] = $this->workspace();
        app(TenantContext::class)->run($actor->id, $org, function () use ($actor, $org): void {
            for ($index = 0; $index < 27; $index++) {
                app(Pipeline::class)->create($actor->id, $org, $this->lead(['brand_name' => 'Paged '.$index, 'value_minor' => '999999999999999999']));
            }
            app(Pipeline::class)->create($actor->id, $org, $this->lead(['currency' => 'USD', 'value_minor' => '123']));
        });
        $first = $this->getJson('/pipeline?q=Paged')->assertJsonCount(25, 'entries')->assertJsonPath('totals.0.amount_minor', '26999999999999999973');
        $cursor = $first->json('nextCursor');
        $second = $this->getJson('/pipeline?q=Paged&cursor='.$cursor)->assertJsonCount(2, 'entries')->assertJsonPath('nextCursor', null)
            ->assertJsonPath('totals.0.amount_minor', '26999999999999999973');
        $this->assertEmpty(array_intersect(array_column($first->json('entries'), 'id'), array_column($second->json('entries'), 'id')));
        $this->getJson('/pipeline')->assertJsonCount(2, 'totals');
        $this->getJson('/pipeline?q=%25')->assertJsonCount(0, 'entries');
        $this->getJson('/pipeline?q=0')->assertJsonCount(3, 'entries');
        $this->getJson('/pipeline?stage=unknown')->assertUnprocessable();
        $this->postJson('/pipeline', $this->lead(['value_minor' => '9007199254740993']))->assertCreated();
        foreach (['1.5', '-1', '1000000000000000000', '1e3'] as $bad) {
            $this->postJson('/pipeline', $this->lead(['value_minor' => $bad]))->assertUnprocessable();
        }
    }

    public function test_rls_denies_every_foreign_operation_and_activity_parent_and_actor_forgery(): void
    {
        [$actor, $org] = $this->workspace();
        $other = User::factory()->create();
        $foreignOrg = app(Workspaces::class)->create($other->id, 'Other');
        $foreign = app(TenantContext::class)->run($other->id, $foreignOrg, fn () => app(Pipeline::class)->create($other->id, $foreignOrg, $this->lead()));
        $own = $this->postJson('/pipeline', $this->lead())->json('id');
        $this->assertSame(0, DB::table('pipeline_entries')->count());
        $this->assertSame(0, DB::table('pipeline_activities')->count());
        app(TenantContext::class)->run($actor->id, $org, function () use ($actor, $other, $org, $foreignOrg, $foreign, $own): void {
            $this->assertSame(0, DB::table('pipeline_entries')->where('id', $foreign)->count());
            $this->assertSame(0, DB::table('pipeline_activities')->where('pipeline_entry_id', $foreign)->count());
            $this->assertSame(0, DB::table('pipeline_entries')->where('id', $foreign)->update(['notes' => 'forged']));
            $this->assertSame(0, DB::table('pipeline_entries')->where('id', $foreign)->delete());
            $this->assertSame(0, DB::table('pipeline_activities')->update(['body' => 'rewrite']));
            $this->assertSame(0, DB::table('pipeline_activities')->delete());
            $probes = [
                fn () => DB::table('pipeline_entries')->insert([...$this->lead(), 'id' => (string) Str::uuid(), 'org_id' => $foreignOrg]),
                fn () => DB::table('pipeline_entries')->where('id', $own)->update(['org_id' => $foreignOrg]),
                fn () => DB::table('pipeline_activities')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'actor_id' => $other->id, 'pipeline_entry_id' => $own, 'kind' => 'note', 'body' => 'forged']),
                fn () => DB::table('pipeline_activities')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'actor_id' => $actor->id, 'pipeline_entry_id' => $foreign, 'kind' => 'note', 'body' => 'foreign parent']),
            ];
            foreach ($probes as $index => $probe) {
                try {
                    DB::transaction($probe);
                    $this->fail('Database accepted forged operation '.$index);
                } catch (QueryException $exception) {
                    $this->assertSame($index === 3 ? '23503' : '42501', $exception->errorInfo[0]);
                }
            }
        });
        $this->assertNull(DB::selectOne('SELECT app_actor() AS actor')->actor);
    }

    public function test_failed_transaction_rolls_back_lead_activity_and_redacted_audit(): void
    {
        [$actor, $org] = $this->workspace();
        try {
            app(TenantContext::class)->run($actor->id, $org, function () use ($actor, $org): void {
                app(Pipeline::class)->create($actor->id, $org, $this->lead());
                throw new LogicException('Abort pipeline capture');
            });
        } catch (LogicException $exception) {
            $this->assertSame('Abort pipeline capture', $exception->getMessage());
        }
        app(TenantContext::class)->run($actor->id, $org, function (): void {
            $this->assertSame(0, DB::table('pipeline_entries')->count());
            $this->assertSame(0, DB::table('pipeline_activities')->count());
            $this->assertSame(0, DB::table('audit_logs')->where('event', 'pipeline.created')->count());
        });
    }
}
