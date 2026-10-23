<?php

namespace Tests\Acceptance;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;

class PartnershipSecurityTest extends AcceptanceCase
{
    private function setupPair(): array
    {
        $brand = User::factory()->create();
        $creator = User::factory()->create();
        $bo = app(Workspaces::class)->create($brand->id, 'Brand', 'brand');
        $co = app(Workspaces::class)->create($creator->id, 'Creator');
        $invite = $this->actingAs($brand)->withSession(['active_org_id' => $bo])->postJson('/partner-invitations', ['email' => $creator->email])->assertCreated()->json();
        $token = explode('#', $invite['url'])[1];

        return [$brand, $bo, $creator, $co, $invite['id'], $token];
    }

    private function accepting(User $actor, string $org, string $token): Process
    {
        $process = new Process([PHP_BINARY, 'tests/Fixtures/partnership-probe.php', (string) $actor->id, $org, hash('sha256', $token)], base_path(), ['APP_ENV' => 'testing', 'TEST_MIGRATION_DB_PASSWORD' => false]);
        $process->setTimeout(15);
        $process->start();
        $this->eventually(fn () => str_contains($process->getOutput(), "\n"), 'Acceptance process failed: '.$process->getErrorOutput());

        return $process;
    }

    public function test_revocation_that_wins_invitation_lock_denies_waiting_acceptance(): void
    {
        [$brand, $bo, $creator, $co, $id, $token] = $this->setupPair();
        $this->control->beginTransaction();
        $this->control->table('partner_invitations')->where('id', $id)->update(['status' => 'revoked']);
        $waiting = $this->accepting($creator, $co, $token);
        try {
            $this->assertBlocked($waiting);
            $this->control->commit();
            $this->assertSame(404, $this->probeResult($waiting)['status']);
            $this->assertSame(0, $this->control->table('partnerships')->where('invitation_id', $id)->count());
        } finally {
            $waiting->stop(0);
        }
    }

    public function test_duplicate_acceptance_serializes_and_creates_one_relationship(): void
    {
        [$brand, $bo, $creator, $co, $id, $token] = $this->setupPair();
        $waiting = null;
        try {
            app(TenantContext::class)->run($creator->id, $co, function () use ($creator, $co, $token, &$waiting): void {
                $this->assertTrue(DB::selectOne('SELECT app_accept_partner(?::text,?::uuid) AS allowed', [hash('sha256', $token), (string) Str::uuid()])->allowed);
                $waiting = $this->accepting($creator, $co, $token);
                $this->assertBlocked($waiting);
            });
            $this->assertSame(404, $this->probeResult($waiting)['status']);
            $this->assertSame(1, $this->control->table('partnerships')->where('invitation_id', $id)->count());
        } finally {
            $waiting?->stop(0);
        }
    }

    public function test_expiry_roles_membership_revocation_partial_props_and_foreign_files(): void
    {
        $this->withoutVite();
        [$brand, $bo, $creator, $co, $id, $token] = $this->setupPair();
        $accept = ['token' => $token, 'consent' => true];
        $this->control->table('partner_invitations')->where('id', $id)->update(['expires_at' => now()->subSecond()]);
        $this->actingAs($creator)->withSession(['active_org_id' => $co])->postJson('/partnerships/accept', $accept)->assertNotFound();
        $this->control->table('partner_invitations')->where('id', $id)->update(['expires_at' => now()->addDay()]);
        foreach (['viewer', 'member', 'manager'] as $role) {
            $this->control->table('memberships')->where('org_id', $co)->update(['role' => $role]);
            $this->postJson('/partnerships/accept', $accept)->assertNotFound();
        }
        $this->control->table('memberships')->where('org_id', $co)->update(['role' => 'admin']);
        $this->postJson('/partnerships/accept', $accept)->assertOk();
        $this->get('/partner/leads', ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '', 'X-Inertia-Partial-Component' => 'Portal', 'X-Inertia-Partial-Data' => 'relationships'])
            ->assertOk()->assertJsonCount(1, 'props.relationships')->assertJsonMissingPath('props.pipeline')->assertDontSee('token_hash')->assertDontSee($brand->email);
        $this->actingAs($brand)->withSession(['active_org_id' => $bo])->get('/brand')->assertInertia(fn (Assert $page) => $page->component('Portal')->where('pipeline', null)->has('relationships', 1)->etc());
        $this->get('/onboarding-requests/'.Str::uuid().'/documents/'.Str::uuid())->assertNotFound();
        $relationship = $this->control->table('partnerships')->where('invitation_id', $id)->value('id');
        foreach (['viewer', 'member', 'manager'] as $role) {
            $this->control->table('memberships')->where('org_id', $bo)->update(['role' => $role]);
            $this->deleteJson('/partnerships/'.$relationship)->assertNotFound();
            $this->postJson('/partner-invitations', ['email' => $creator->email])->assertConflict();
            $this->getJson('/brand')->assertJsonPath('canManage', false)->assertJsonCount(0, 'invitations');
        }
        $this->control->table('memberships')->where('org_id', $co)->update(['revoked_at' => now()]);
        $this->actingAs($creator)->withSession(['active_org_id' => $co])->getJson('/partner')->assertForbidden();
        $this->postJson('/partnerships/accept', $accept)->assertForbidden();
        // Relationship status is distinct from membership; the brand's authorized view still has it.
        $this->actingAs($brand)->withSession(['active_org_id' => $bo])->getJson('/brand')->assertJsonCount(1, 'relationships');
    }
}
