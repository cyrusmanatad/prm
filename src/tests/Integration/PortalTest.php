<?php

namespace Tests\Integration;

use App\Jobs\Middleware\TenantJobContext;
use App\Models\User;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
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
        foreach (['/pro' => '/pro/leads', '/pipeline' => '/pro/leads', '/crm' => '/pro/contacts', '/outreach-templates' => '/pro/outreach-templates', '/onboarding-requests' => '/pro/onboarding', '/public-presence' => '/pro/media-kit'] as $old => $new) {
            $this->get($old.'?entry='.$own)->assertRedirect($new.'?entry='.$own);
            $this->get($new)->assertOk();
        }
        // Pre-rename Professional portal bookmarks keep working.
        $this->get('/partner')->assertRedirect('/pro');
        $this->get('/partner/contacts?entry='.$own)->assertRedirect('/pro/contacts?entry='.$own);
        $this->get('/partner/login')->assertRedirect('/pro/login');
        $this->getJson('/pro/leads?entry='.$own)->assertOk()->assertJsonPath('pipeline.selected.id', $own)->assertDontSee('Hidden lead');
        $this->getJson('/pro/leads?stage=lost')->assertJsonCount(0, 'pipeline.entries');
        $this->getJson('/pro/leads?stage=invalid')->assertUnprocessable();
        $this->getJson('/pro/leads?entry='.$foreign)->assertNotFound();
        $this->getJson('/pro/leads?cursor='.$foreign)->assertNotFound();
        $this->get('/pro/inbox')->assertNotFound();
        $this->get('/pro/campaigns')->assertNotFound();
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
        // Browser visits explain the refusal on the acceptance form instead of a generic 404 page.
        $this->select($other, $oo)->from('/partnerships/accept')->post('/partnerships/accept', ['token' => explode('#', $invite['url'])[1], 'consent' => true], ['X-Inertia' => 'true'])
            ->assertRedirect('/partnerships/accept')->assertSessionHasErrors('token');
        $this->select($creator, $co)->postJson('/partnerships/accept', ['token' => explode('#', $invite['url'])[1], 'consent' => false])->assertUnprocessable();
        $this->accept($creator, $co, $invite)->assertOk();
        $this->accept($creator, $co, $invite)->assertNotFound();
        $relationship = $this->getJson('/pro')->assertJsonCount(1, 'relationships')->json('relationships.0');
        $this->select($brand, $bo)->getJson('/brand')->assertJsonPath('relationships.0.id', $relationship['id'])->assertJsonPath('relationships.0.creator_name', $relationship['creator_name'])->assertDontSee('Private rates')->assertDontSee('Secret prospect');
        $this->getJson('/pipeline?entry='.$lead)->assertNotFound();
        $this->postJson('/workspace/select', ['org_id' => $co])->assertForbidden();
        $this->select($other, $oo)->getJson('/brand')->assertJsonCount(0, 'relationships');
        $this->deleteJson('/partnerships/'.$relationship['id'])->assertNotFound();
        // A creator may independently partner with a second brand.
        $second = $this->invite($other, $oo, $creator->email);
        $this->accept($creator, $co, $second)->assertOk();
        $this->getJson('/pro')->assertJsonCount(2, 'relationships');
        $duplicate = $this->invite($brand, $bo, $creator->email);
        $this->accept($creator, $co, $duplicate)->assertNotFound();
        $this->select($brand, $bo)->deleteJson('/partner-invitations/'.$duplicate['id'])->assertOk();
        $this->accept($creator, $co, $duplicate)->assertNotFound();
        $this->select($creator, $co)->deleteJson('/partnerships/'.$relationship['id'])->assertOk();
        $this->getJson('/pro')->assertJsonCount(1, 'relationships');
        $this->select($brand, $bo)->getJson('/brand')->assertJsonCount(0, 'relationships');
        $this->select($creator, $co)->getJson('/pipeline?entry='.$lead)->assertOk()->assertSee('Private rates');
        $this->select($brand, $bo)->get('/pro')->assertRedirect('/workspaces');
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
        $this->withSession(['portal' => 'pro'])->get('/portal/enter')->assertRedirect('/workspaces');
        $this->getJson('/pro')->assertRedirect('/workspaces');
    }

    public function test_invitation_registers_new_recipients_and_adds_a_workspace_for_existing_accounts(): void
    {
        $this->withoutVite();
        [$brand, $bo] = $this->workspace('brand');
        [$existing, $eo] = $this->workspace('creator');
        $email = 'invitee-'.Str::uuid().'@example.test';
        $token = explode('#', $this->invite($brand, $bo, $email)['url'])[1];
        $existingToken = explode('#', $this->invite($brand, $bo, $existing->email)['url'])[1];
        $this->post('/logout');
        $this->postJson('/partnerships/prepare', ['token' => str_repeat('b', 64)])->assertNotFound();

        // A new email registers a separate account locked to the invited recipient.
        $this->post('/partnerships/prepare', ['token' => $token])->assertRedirect('/pro/register');
        $this->get('/pro/register')->assertInertia(fn (Assert $page) => $page->component('Auth')->where('email', $email)->where('invitation', true)->etc());
        $password = ['password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123'];
        $this->from('/pro/register')->post('/pro/register', ['name' => 'Elsewhere', 'email' => 'other-'.Str::uuid().'@example.test', ...$password])->assertSessionHasErrors('email');
        $this->post('/pro/register', ['name' => 'Invitee', 'email' => $email, ...$password])->assertRedirect('/verify-email');
        $invitee = User::where('email', $email)->firstOrFail();
        $invitee->markEmailAsVerified();
        $this->actingAs($invitee->fresh())->get('/portal/enter')->assertRedirect('/partnerships/accept');
        $listing = app(Workspaces::class)->listing($invitee->id);
        $this->assertCount(1, $listing);
        $this->assertSame('creator', $listing[0]->kind);
        $this->post('/partnerships/accept', ['token' => $token, 'consent' => true])->assertRedirect('/pro/leads');
        $this->getJson('/pro')->assertJsonCount(1, 'relationships');
        $this->post('/logout');

        // An existing email signs in and gains a new creator workspace instead of reusing its current one.
        $this->post('/partnerships/prepare', ['token' => $existingToken])->assertRedirect('/pro/login');
        $this->get('/pro/login')->assertInertia(fn (Assert $page) => $page->where('email', $existing->email)->where('invitation', true)->etc());
        $this->post('/pro/login', ['email' => $existing->email, 'password' => 'password'])->assertRedirect('/portal/enter');
        $this->get('/portal/enter')->assertRedirect('/partnerships/accept');
        $added = collect(app(Workspaces::class)->listing($existing->id))->firstWhere('id', '!=', $eo);
        $this->assertCount(2, app(Workspaces::class)->listing($existing->id));
        $this->assertSame('creator', $added->kind);
        $this->post('/partnerships/accept', ['token' => $existingToken, 'consent' => true])->assertRedirect('/pro/leads');
        $this->getJson('/pro')->assertJsonCount(1, 'relationships');
        $this->select($existing, $eo)->getJson('/pro')->assertJsonCount(0, 'relationships');
        $this->get('/portal/enter');
        $this->assertCount(2, app(Workspaces::class)->listing($existing->id));
    }

    public function test_brand_sign_up_link_registers_or_signs_in_partners_and_can_be_replaced_or_turned_off(): void
    {
        $this->withoutVite();
        [$brand, $bo] = $this->workspace('brand');
        [$existing, $eo] = $this->workspace('creator');
        $this->select($existing, $eo)->postJson('/partner-join-link')->assertForbidden();
        $link = $this->select($brand, $bo)->postJson('/partner-join-link')->assertOk()->json();
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{5}-[0-9A-Z]{5}$/', $link['code']);
        $this->assertStringEndsWith('/join#'.$link['code'], $link['url']);
        $this->getJson('/brand')->assertJsonPath('joinLink.code', $link['code'])->assertDontSee('sealed_code');
        $this->post('/logout');
        $this->postJson('/join/prepare', ['code' => 'AAAAA-AAAAA'])->assertNotFound();

        // A new partner types the code loosely, registers any email and joins from the provisioned workspace.
        $this->post('/join/prepare', ['code' => strtolower(str_replace('-', ' ', $link['code']))])->assertRedirect('/join');
        $brandName = collect(app(Workspaces::class)->listing($brand->id))->firstWhere('id', $bo)->name;
        $this->get('/join')->assertInertia(fn (Assert $page) => $page->component('JoinBrand')->where('brand', $brandName)->etc());
        $this->get('/pro/register')->assertInertia(fn (Assert $page) => $page->where('joining', $brandName)->missing('invitation')->etc());
        $email = 'joiner-'.Str::uuid().'@example.test';
        $this->post('/pro/register', ['name' => 'Joiner', 'email' => $email, 'password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123'])->assertRedirect('/verify-email');
        $joiner = User::where('email', $email)->firstOrFail();
        $joiner->markEmailAsVerified();
        $this->actingAs($joiner->fresh())->get('/portal/enter')->assertRedirect('/join');
        $this->assertCount(1, app(Workspaces::class)->listing($joiner->id));
        $this->post('/join/accept', ['consent' => true])->assertRedirect('/pro/leads');
        $this->getJson('/pro')->assertJsonCount(1, 'relationships')->assertJsonPath('relationships.0.brand_name', $brandName);
        $this->post('/logout');

        // An existing account signs in and joins from a newly added creator workspace.
        $this->post('/join/prepare', ['code' => $link['code']])->assertRedirect('/join');
        $this->post('/pro/login', ['email' => $existing->email, 'password' => 'password'])->assertRedirect('/portal/enter');
        $this->get('/portal/enter')->assertRedirect('/join');
        $this->assertCount(2, app(Workspaces::class)->listing($existing->id));
        $this->post('/join/accept', ['consent' => true])->assertRedirect('/pro/leads');
        $added = $this->getJson('/pro')->assertJsonCount(1, 'relationships')->json('workspace.id');
        $this->assertNotSame($eo, $added);
        $this->post('/join/prepare', ['code' => $link['code']]);
        $this->postJson('/join/accept', ['consent' => true])->assertNotFound();
        $this->post('/logout');

        // Replacing and turning off the link stop new joins without ending existing partnerships.
        $replacement = $this->select($brand, $bo)->postJson('/partner-join-link')->assertOk()->json();
        $this->assertNotSame($link['code'], $replacement['code']);
        $this->getJson('/brand')->assertJsonCount(2, 'relationships');
        $this->deleteJson('/partner-join-link')->assertOk();
        $this->getJson('/brand')->assertJsonPath('joinLink', null)->assertJsonCount(2, 'relationships');
        $this->deleteJson('/partner-join-link')->assertNotFound();
        $this->post('/logout');
        $this->postJson('/join/prepare', ['code' => $link['code']])->assertNotFound();
        $this->postJson('/join/prepare', ['code' => $replacement['code']])->assertNotFound();
    }

    public function test_professional_portal_lists_closed_deals_and_workspaces_page_manages_partnerships(): void
    {
        $this->withoutVite();
        [$brand, $bo] = $this->workspace('brand');
        [$creator, $co] = $this->workspace('creator');
        $won = $this->select($creator, $co)->postJson('/pipeline', ['brand_name' => 'Won studio', 'currency' => 'PHP', 'value_minor' => '500'])->json('id');
        $this->postJson('/pipeline', ['brand_name' => 'Open studio', 'currency' => 'PHP', 'value_minor' => '900'])->assertCreated();
        $this->postJson('/pipeline/'.$won.'/stage', ['stage' => 'onboarded', 'version' => 1])->assertOk();
        // Closed deals are won leads (Onboarded, Active, Repeat Client) across all pages, not partnerships (D31).
        $this->getJson('/pro')->assertJsonPath('closedDeals.count', 1)->assertJsonCount(1, 'closedDeals.recent')
            ->assertJsonPath('closedDeals.recent.0.brand_name', 'Won studio')->assertJsonPath('closedDeals.recent.0.value_minor', '500')
            ->assertJsonPath('closedDeals.totals.0.amount_minor', '500');
        $this->select($brand, $bo)->getJson('/brand')->assertJsonPath('closedDeals', null);

        $brandName = collect(app(Workspaces::class)->listing($brand->id))->firstWhere('id', $bo)->name;
        $this->accept($creator, $co, $this->invite($brand, $bo, $creator->email))->assertOk();
        $this->select($creator, $co)->get('/workspaces')->assertInertia(fn (Assert $page) => $page
            ->where('partnerships.rows.0.brand_name', $brandName)->where('partnerships.canManage', true)->etc());
        $relationship = $this->getJson('/pro')->json('relationships.0.id');
        $this->delete('/partnerships/'.$relationship, ['workspaces' => true])->assertRedirect('/workspaces');
        $this->get('/workspaces')->assertInertia(fn (Assert $page) => $page->has('partnerships.rows', 0)->etc());
        $this->select($brand, $bo)->get('/workspaces')->assertInertia(fn (Assert $page) => $page->where('partnerships', null)->etc());
    }

    public function test_omitted_stages_keep_details_and_aggregate_semantics(): void
    {
        [$creator, $co] = $this->workspace('creator');
        foreach (['repeat-client', 'lost'] as $stage) {
            $id = $this->select($creator, $co)->postJson('/pipeline', ['brand_name' => $stage, 'currency' => 'PHP', 'value_minor' => '100'])->assertCreated()->json('id');
            $this->postJson('/pipeline/'.$id.'/stage', ['stage' => $stage, 'version' => 1])->assertOk();
            $this->getJson('/pipeline?entry='.$id)->assertOk()->assertJsonPath('selected.stage', $stage);
        }
        $this->getJson('/pro')->assertJsonCount(2, 'pipeline.entries')->assertJsonPath('pipeline.totals.0.amount_minor', '100');
    }
}
