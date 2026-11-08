<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\PartnershipInvitation;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;

class JoinLinkTest extends AcceptanceCase
{
    private function pair(): array
    {
        $brand = User::factory()->create();
        $creator = User::factory()->create();
        $bo = app(Workspaces::class)->create($brand->id, 'Join Brand', 'brand');
        $co = app(Workspaces::class)->create($creator->id, 'Join Creator');
        $this->actingAs($brand)->withSession(['active_org_id' => $bo])->postJson('/partner-join-link', ['link_id' => null])->assertOk();
        $link = $this->getJson('/brand')->json('joinLink');

        return [$brand, $bo, $creator, $co, $link];
    }

    private function prepare(User $user, string $org, array $link): void
    {
        $this->actingAs($user)->withSession(['active_org_id' => $org])->post('/join/prepare', ['code' => strtolower(str_replace('-', ' ', $link['code']))])->assertRedirect('/portal/enter');
    }

    public function test_normalization_stale_management_and_preservation_of_established_partnerships(): void
    {
        [$brand, $bo, $creator, $co, $link] = $this->pair();
        $this->assertSame('0112345678', PartnershipInvitation::normalizeCode('oIl23-45678'));
        $this->assertNull(PartnershipInvitation::normalizeCode('bad'));
        $this->prepare($creator, $co, $link);
        $this->postJson('/join/accept', ['consent' => false])->assertUnprocessable();
        $this->postJson('/join/accept', ['consent' => true])->assertOk()->assertSessionMissing('partnership_join');
        $this->prepare($creator, $co, $link);
        $this->postJson('/join/accept', ['consent' => true])->assertNotFound();
        $this->actingAs($brand)->withSession(['active_org_id' => $bo]);
        $this->postJson('/partner-join-link', ['link_id' => null])->assertConflict();
        $this->postJson('/partner-join-link', ['link_id' => $link['id']])->assertOk();
        $current = $this->getJson('/brand')->assertJsonCount(1, 'relationships')->json('joinLink');
        $this->deleteJson('/partner-join-link', ['link_id' => $link['id']])->assertConflict();
        $this->postJson('/partner-join-link', ['link_id' => $link['id']])->assertConflict();
        $this->postJson('/join/prepare', ['code' => $link['code']])->assertNotFound();
        $this->deleteJson('/partner-join-link', ['link_id' => $current['id']])->assertOk();
        $this->getJson('/brand')->assertJsonPath('joinLink', null)->assertJsonCount(1, 'relationships');
        $this->postJson('/join/prepare', ['code' => $current['code']])->assertNotFound();
        $this->assertSame(1, $this->control->table('audit_logs')->where('event', 'partnership.joined')->where('org_id', $co)->count());
        // Rollback refuses without deleting the accepted relationship or schema.
        $this->control->beginTransaction();
        try {
            $this->control->unprepared(file_get_contents(database_path('sql/partner-join-links-down.sql')));
            $this->fail('Rollback must refuse accepted link partnerships');
        } catch (QueryException $e) {
            $this->assertStringContainsString('link-origin partnerships must be preserved', $e->getMessage());
        } finally {
            $this->control->rollBack();
        }
        $this->assertSame(1, $this->control->table('partnerships')->where('join_link_id', $link['id'])->count());
    }

    public function test_permissions_rls_grants_and_verification_are_rechecked(): void
    {
        [$brand, $bo, $creator, $co, $link] = $this->pair();
        foreach (['viewer', 'member', 'manager'] as $role) {
            $this->control->table('memberships')->where('org_id', $bo)->update(['role' => $role]);
            $this->actingAs($brand)->withSession(['active_org_id' => $bo])->getJson('/brand')->assertJsonPath('joinLink', null);
            $this->postJson('/partner-join-link', ['link_id' => $link['id']])->assertConflict();
            $this->deleteJson('/partner-join-link', ['link_id' => $link['id']])->assertConflict();
            $this->control->table('memberships')->where('org_id', $co)->update(['role' => $role]);
            $this->prepare($creator, $co, $link);
            $this->postJson('/join/accept', ['consent' => true])->assertNotFound();
        }
        $this->control->table('memberships')->where('org_id', $co)->update(['role' => 'admin']);
        app(TenantContext::class)->run($creator->id, $co, function () use ($creator, $link): void {
            $this->assertSame(0, DB::table('partner_join_links')->count());
            $this->assertFalse(DB::selectOne("SELECT has_table_privilege(current_user,'partner_join_links','INSERT') AS allowed")->allowed);
            $this->assertFalse(DB::selectOne("SELECT has_table_privilege(current_user,'partner_join_links','UPDATE') AS allowed")->allowed);
            $this->assertFalse(DB::selectOne("SELECT has_function_privilege('public','app_join_partner(text,uuid)','EXECUTE') AS allowed")->allowed);
            $this->control->table('users')->where('id', $creator->id)->update(['email_verified_at' => null]);
            $this->assertFalse(DB::selectOne('SELECT app_join_partner(?,?::uuid) AS allowed', [hash('sha256', str_replace('-', '', $link['code'])), (string) Str::uuid()])->allowed);
        });
        $this->assertTrue($this->control->selectOne("SELECT relforcerowsecurity AS enabled FROM pg_class WHERE oid='partner_join_links'::regclass")->enabled);
    }

    public function test_workspace_selection_professional_routing_context_exchange_and_expiry(): void
    {
        $this->withoutVite();
        [$brand, $bo, $creator, $co, $link] = $this->pair();
        $pro = app(Workspaces::class)->create($creator->id, 'Professional Join', experience: 'professional');
        $this->prepare($creator, $pro, $link);
        $this->get('/portal/enter')->assertRedirect('/join/select');
        $this->get('/join/select')->assertRedirect('/join');
        $this->get('/join')->assertInertia(fn (Assert $page) => $page->where('workspace.name', 'Professional Join')->where('brand', 'Join Brand')->etc());
        $this->post('/join/accept', ['consent' => true])->assertRedirect('/professional');
        $this->prepare($creator, $co, $link);
        $this->withSession(['active_org_id' => null])->get('/join/select')->assertRedirect('/workspaces');
        $this->post('/workspace/select', ['org_id' => $co])->assertRedirect('/join/select');
        $this->withSession(['partnership_join' => ['expires' => time() - 1]])->post('/join/accept', ['consent' => true])->assertRedirect('/join')->assertSessionHasErrors('code');
        $this->prepare($creator, $co, $link);
        $this->post('/partnerships/prepare', ['token' => str_repeat('a', 64)])->assertSessionMissing('partnership_join');
        $this->post('/join/prepare', ['code' => $link['code']])->assertSessionMissing('partnership_token');
        $this->assertCount(2, app(Workspaces::class)->listing($creator->id));
    }

    private function joining(User $actor, string $org, array $link, string $action = 'accept'): Process
    {
        $process = new Process([PHP_BINARY, 'tests/Fixtures/join-link-probe.php', (string) $actor->id, $org, str_replace('-', '', $link['code']), $action, $link['id']], base_path(), ['APP_ENV' => 'testing', 'TEST_MIGRATION_DB_PASSWORD' => false]);
        $process->setTimeout(15);
        $process->start();
        $this->eventually(fn () => str_contains($process->getOutput(), "\n"), $process->getErrorOutput());

        return $process;
    }

    public function test_duplicate_acceptance_and_waiting_disable_serialize_with_audit_atomicity(): void
    {
        [$brand, $bo, $creator, $co, $link] = $this->pair();
        $waiting = null;
        try {
            app(TenantContext::class)->run($creator->id, $co, function () use ($creator, $co, $link, &$waiting): void {
                $this->assertTrue(DB::selectOne('SELECT app_join_partner(?,?::uuid) AS allowed', [hash('sha256', str_replace('-', '', $link['code'])), (string) Str::uuid()])->allowed);
                $waiting = $this->joining($creator, $co, $link);
                $this->assertBlocked($waiting);
            });
            $this->assertFalse($this->probeResult($waiting)['allowed']);
            app(TenantContext::class)->run($creator->id, $co, function () use ($brand, $bo, $link, &$waiting): void {
                // Take the acceptance lifecycle lock, then prove disable waits.
                DB::selectOne('SELECT app_join_partner(?,?::uuid)', [hash('sha256', str_replace('-', '', $link['code'])), (string) Str::uuid()]);
                $waiting = $this->joining($brand, $bo, $link, 'disable');
                $this->assertBlocked($waiting);
            });
            $this->assertTrue($this->probeResult($waiting)['allowed']);
            $this->assertSame(1, $this->control->table('partnerships')->where('join_link_id', $link['id'])->count());
        } finally {
            $waiting?->stop(0);
        }
    }

    public function test_revocation_winning_lock_denies_waiting_join_and_failed_audit_rolls_back(): void
    {
        [$brand, $bo, $creator, $co, $link] = $this->pair();
        $waiting = null;
        try {
            app(TenantContext::class)->run($brand->id, $bo, function () use ($creator, $co, $link, &$waiting): void {
                $this->assertTrue(DB::selectOne('SELECT app_disable_join_link(?::uuid,?::uuid) AS allowed', [(string) Str::uuid(), $link['id']])->allowed);
                $waiting = $this->joining($creator, $co, $link);
                $this->assertBlocked($waiting);
            });
            $this->assertFalse($this->probeResult($waiting)['allowed']);
            $this->assertSame(0, $this->control->table('partnerships')->where('join_link_id', $link['id'])->count());
        } finally {
            $waiting?->stop(0);
        }
        // Duplicate audit primary key aborts issuance and its replacement together.
        $this->expectException(QueryException::class);
        try {
            app(TenantContext::class)->run($brand->id, $bo, fn () => DB::selectOne('SELECT app_issue_join_link(?::uuid,?,?,?::uuid,NULL) AS allowed', [(string) Str::uuid(), hash('sha256', Str::random(10)), encrypt('0123456789'), $this->control->table('audit_logs')->where('org_id', $bo)->value('id')]));
        } finally {
            $this->assertSame(1, $this->control->table('partner_join_links')->where('brand_org_id', $bo)->count());
        }
    }
}
