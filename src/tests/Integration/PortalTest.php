<?php

namespace Tests\Integration;

use App\Jobs\Middleware\TenantJobContext;
use App\Models\User;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalTest extends TestCase
{
    private function workspace(string $kind, ?User $user = null): array
    {
        $user ??= User::factory()->create();

        return [$user, app(Workspaces::class)->create($user->id, $kind.' '.Str::random(10), $kind)];
    }

    private function select(User $user, string $org): static
    {
        $this->actingAs($user)->withSession(['active_org_id' => $org]);

        return $this;
    }

    private function invite(User $brand, string $org, string $email): array
    {
        return $this->select($brand, $org)->postJson('/partner-invitations', ['email' => $email])->assertCreated()->json();
    }

    private function accept(User $creator, string $org, array $invite)
    {
        return $this->select($creator, $org)->postJson('/partnerships/accept', ['token' => explode('#', $invite['url'])[1], 'consent' => true]);
    }

    public function test_partner_routes_preserve_bookmarks_filters_and_private_detail_boundaries(): void
    {
        $this->withoutVite();
        [$creator, $org] = $this->workspace('creator');
        [$other, $foreignOrg] = $this->workspace('creator');
        $foreign = $this->select($other, $foreignOrg)->postJson('/pipeline', ['brand_name' => 'Hidden lead', 'notes' => 'Hidden note', 'currency' => 'PHP', 'value_minor' => '100'])->json('id');
        $own = $this->select($creator, $org)->postJson('/pipeline', ['brand_name' => 'Own lead', 'currency' => 'PHP', 'value_minor' => '200'])->json('id');
        foreach (['/partner' => '/partner/leads', '/pipeline' => '/partner/leads', '/crm' => '/partner/contacts', '/outreach-templates' => '/partner/outreach-templates', '/onboarding-requests' => '/partner/onboarding', '/public-presence' => '/partner/media-kit'] as $old => $new) {
            $this->get($old.'?entry='.$own)->assertRedirect($new.'?entry='.$own);
            $this->get($new)->assertOk();
        }
        $this->getJson('/partner/leads?entry='.$own)->assertOk()->assertJsonPath('pipeline.selected.id', $own)->assertDontSee('Hidden lead');
        $this->getJson('/partner/leads?stage=lost')->assertJsonCount(0, 'pipeline.entries');
        $this->getJson('/partner/leads?stage=invalid')->assertUnprocessable();
        $this->getJson('/partner/leads?entry='.$foreign)->assertNotFound();
        $this->getJson('/partner/leads?cursor='.$foreign)->assertNotFound();
        $this->get('/partner/inbox')->assertNotFound();
        $this->get('/partner/campaigns')->assertNotFound();
    }

    public function test_shared_identity_projection_privacy_and_replay_revocation(): void
    {
        [$brand, $bo] = $this->workspace('brand');
        [$creator, $co] = $this->workspace('creator');
        [$other, $oo] = $this->workspace('brand');
        $lead = $this->select($creator, $co)->postJson('/pipeline', ['brand_name' => 'Secret prospect', 'notes' => 'Private rates', 'currency' => 'PHP', 'value_minor' => '456'])->assertCreated()->json('id');
        $invite = $this->invite($brand, $bo, $creator->email);
        $this->getJson('/brand')->assertJsonCount(0, 'relationships')->assertDontSee($creator->email)->assertDontSee('token_hash');
        $this->select($brand, $bo)->postJson('/partner-invitations', ['email' => $creator->email])->assertConflict();
        $this->accept($other, $oo, $invite)->assertNotFound();
        $this->select($creator, $co)->postJson('/partnerships/accept', ['token' => explode('#', $invite['url'])[1], 'consent' => false])->assertUnprocessable();
        $this->accept($creator, $co, $invite)->assertOk();
        $this->accept($creator, $co, $invite)->assertNotFound();
        $relationship = $this->getJson('/partner')->assertJsonCount(1, 'relationships')->json('relationships.0');
        $this->select($brand, $bo)->getJson('/brand')->assertJsonPath('relationships.0.id', $relationship['id'])->assertJsonPath('relationships.0.creator_name', $relationship['creator_name'])->assertDontSee('Private rates')->assertDontSee('Secret prospect');
        $this->getJson('/pipeline?entry='.$lead)->assertNotFound();
        $this->postJson('/workspace/select', ['org_id' => $co])->assertForbidden();
        $this->select($other, $oo)->getJson('/brand')->assertJsonCount(0, 'relationships');
        $this->deleteJson('/partnerships/'.$relationship['id'])->assertNotFound();
        // A creator may independently partner with a second brand.
        $second = $this->invite($other, $oo, $creator->email);
        $this->accept($creator, $co, $second)->assertOk();
        $this->getJson('/partner')->assertJsonCount(2, 'relationships');
        $duplicate = $this->invite($brand, $bo, $creator->email);
        $this->accept($creator, $co, $duplicate)->assertNotFound();
        $this->select($brand, $bo)->deleteJson('/partner-invitations/'.$duplicate['id'])->assertOk();
        $this->accept($creator, $co, $duplicate)->assertNotFound();
        $this->select($creator, $co)->deleteJson('/partnerships/'.$relationship['id'])->assertOk();
        $this->getJson('/partner')->assertJsonCount(1, 'relationships');
        $this->select($brand, $bo)->getJson('/brand')->assertJsonCount(0, 'relationships');
        $this->select($creator, $co)->getJson('/pipeline?entry='.$lead)->assertOk()->assertSee('Private rates');
        $this->select($brand, $bo)->get('/partner')->assertRedirect('/workspaces');
        $this->select($creator, $co)->get('/brand')->assertRedirect('/workspaces');
    }

    public function test_rls_denies_foreign_reads_direct_mutations_forged_names_and_workspace_type_changes(): void
    {
        [$brand, $bo] = $this->workspace('brand');
        [$creator, $co] = $this->workspace('creator');
        [$other, $oo] = $this->workspace('brand');
        $invite = $this->invite($brand, $bo, $creator->email);
        $this->accept($creator, $co, $invite)->assertOk();
        app(TenantContext::class)->run($other->id, $oo, function () use ($bo, $co, $invite): void {
            foreach (['partnerships', 'partner_invitations'] as $table) {
                $this->assertSame(0, DB::table($table)->count());
                $this->assertSame(0, DB::table($table)->update(['status' => 'revoked']));
                $this->assertSame(0, DB::table($table)->delete());
            }
            try {
                DB::transaction(fn () => DB::table('partner_invitations')->insert(['id' => (string) Str::uuid(), 'brand_org_id' => $bo, 'recipient_email' => 'forged@example.test', 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]));
                $this->fail('Forged invitation accepted');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
            }
            try {
                DB::transaction(fn () => DB::table('partnerships')->insert(['id' => (string) Str::uuid(), 'brand_org_id' => $bo, 'creator_org_id' => $co, 'brand_name' => 'Forged', 'creator_name' => 'Forged', 'invitation_id' => $invite['id']]));
                $this->fail('Forged sharing accepted');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
            }
        });
        app(TenantContext::class)->run($brand->id, $bo, function (): void {
            $this->assertSame(0, DB::table('partnerships')->update(['creator_name' => 'Forged']));
            $this->assertSame(0, DB::table('partnerships')->delete());
            try {
                DB::transaction(fn () => DB::table('organizations')->update(['kind' => 'creator']));
                $this->fail('Workspace type changed');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
            }
        });
        (new TenantJobContext)->handle((object) ['actorId' => $other->id, 'orgId' => $oo], fn () => $this->assertSame(0, DB::table('partnerships')->count()));
        $this->assertNull(DB::selectOne('SELECT app_actor() AS actor')->actor);
    }

    public function test_portal_registration_provisions_once_after_verification_without_inferred_access(): void
    {
        $this->withoutVite();
        $email = 'portal-'.Str::uuid().'@example.test';
        $this->post('/brand/register', ['name' => 'New brand', 'email' => $email, 'password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123'])->assertRedirect('/verify-email');
        $user = User::where('email', $email)->firstOrFail();
        $this->assertCount(0, app(Workspaces::class)->listing($user->id));
        $this->post('/logout')->assertRedirect('/login');
        $user->markEmailAsVerified();
        $this->actingAs($user)->get('/portal/enter')->assertRedirect('/brand');
        $this->get('/portal/enter')->assertRedirect('/brand');
        $listing = app(Workspaces::class)->listing($user->id);
        $this->assertCount(1, $listing);
        $this->assertSame('brand', $listing[0]->kind);
        [$wrong, $wo] = $this->workspace('creator');
        [$brand, $bo] = $this->workspace('brand');
        $invite = $this->invite($brand, $bo, $user->email);
        $this->accept($wrong, $wo, $invite)->assertNotFound();
        $this->select($user, $listing[0]->id);
        $this->withSession(['portal' => 'partner'])->get('/portal/enter')->assertRedirect('/workspaces');
        $this->getJson('/partner')->assertRedirect('/workspaces');
    }

    public function test_omitted_stages_keep_details_and_aggregate_semantics(): void
    {
        [$creator, $co] = $this->workspace('creator');
        foreach (['repeat-client', 'lost'] as $stage) {
            $id = $this->select($creator, $co)->postJson('/pipeline', ['brand_name' => $stage, 'currency' => 'PHP', 'value_minor' => '100'])->assertCreated()->json('id');
            $this->postJson('/pipeline/'.$id.'/stage', ['stage' => $stage, 'version' => 1])->assertOk();
            $this->getJson('/pipeline?entry='.$id)->assertOk()->assertJsonPath('selected.stage', $stage);
        }
        $this->getJson('/partner')->assertJsonCount(2, 'pipeline.entries')->assertJsonPath('pipeline.totals.0.amount_minor', '100');
    }
}
