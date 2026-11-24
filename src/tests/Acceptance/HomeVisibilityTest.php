<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\Workspaces;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\BrandFixture;

/** Home pages against the real runtime role and PostgreSQL row security. */
class HomeVisibilityTest extends AcceptanceCase
{
    private function creator(string $experience = 'partner'): array
    {
        $user = User::factory()->create(['password' => Hash::make('SyntheticPassword123!')]);

        return [$user, app(Workspaces::class)->create($user->id, ucfirst($experience).' '.Str::random(6), experience: $experience)];
    }

    private function brand(): array
    {
        $user = User::factory()->create(['password' => Hash::make('SyntheticPassword123!')]);

        return [$user, BrandFixture::create($user->id, 'Brand '.Str::random(6), $this->control)];
    }

    private function member(User $user, string $org): static
    {
        return $this->actingAs($user)->withSession(['active_org_id' => $org]);
    }

    private function join(string $org, string $role): User
    {
        $user = User::factory()->create();
        $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function connect(string $brandOrg, string $creatorOrg, string $name): string
    {
        $invitation = (string) Str::uuid();
        $id = (string) Str::uuid();
        $this->control->table('partner_invitations')->insert(['id' => $invitation, 'brand_org_id' => $brandOrg, 'recipient_email' => Str::random(12).'@example.test',
            'token_hash' => hash('sha256', $invitation), 'status' => 'accepted', 'expires_at' => now()->addDay()]);
        $this->control->table('partnerships')->insert(['id' => $id, 'brand_org_id' => $brandOrg, 'creator_org_id' => $creatorOrg, 'brand_name' => 'Brand',
            'creator_name' => $name, 'invitation_id' => $invitation, 'accepted_at' => now()->subDay()]);

        return $id;
    }

    public function test_home_is_a_separate_destination_for_the_selected_experience_only(): void
    {
        $this->withoutVite();
        [$partner, $partnerOrg] = $this->creator();
        [$consultant, $professionalOrg] = $this->creator('professional');
        [$brandUser, $brandOrg] = $this->brand();

        $this->member($partner, $partnerOrg)->get('/partner/home')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Home')->where('experience', 'partner')->has('home.stages', 7));
        // Existing destinations keep their own pages and landing redirects.
        $this->get('/partner/leads')->assertOk()->assertInertia(fn ($page) => $page->component('Portal'));
        $this->get('/partner')->assertRedirect('/partner/leads');
        $this->get('/brand/home')->assertNotFound();
        $this->get('/professional/home')->assertNotFound();

        $this->member($consultant, $professionalOrg)->get('/professional/home')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Home')->where('experience', 'professional')->where('home.metrics.partnerships', null));
        $this->get('/professional')->assertOk()->assertInertia(fn ($page) => $page->component('Professional'));
        $this->get('/partner/home')->assertRedirect('/professional/home');
        $this->get('/brand/home')->assertNotFound();

        $this->member($brandUser, $brandOrg)->get('/brand/home')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Home')->where('experience', 'brand'));
        $this->get('/brand')->assertOk()->assertInertia(fn ($page) => $page->component('Portal'));
        $this->get('/partner/home')->assertNotFound();
    }

    public function test_creator_home_reads_only_this_workspace_and_hides_conversation_text(): void
    {
        [$partner, $org] = $this->creator();
        [$stranger, $foreign] = $this->creator();
        $this->member($stranger, $foreign)->postJson('/pipeline', ['brand_name' => 'Foreign prospect', 'currency' => 'USD', 'value_minor' => '777'])->assertCreated();
        $this->postJson('/reminders', ['title' => 'Foreign reminder', 'due_at' => now()->subHour()->toIso8601String()])->assertCreated();

        $own = $this->member($partner, $org)->postJson('/pipeline', ['brand_name' => 'Own prospect', 'currency' => 'PHP', 'value_minor' => '150000',
            'next_action_due' => now()->subHour()->toIso8601String()])->assertCreated()->json('id');
        $lost = $this->postJson('/pipeline', ['brand_name' => 'Lost prospect', 'currency' => 'PHP', 'value_minor' => '999'])->assertCreated()->json('id');
        $this->postJson('/pipeline/'.$lost.'/stage', ['stage' => 'lost', 'version' => 1])->assertOk();
        $this->postJson('/pipeline/'.$own.'/activities', ['kind' => 'note', 'body' => 'Private rate card 45000', 'version' => 1])->assertCreated();
        $this->postJson('/reminders', ['title' => 'Own reminder', 'due_at' => now()->subHour()->toIso8601String()])->assertCreated();

        $home = $this->getJson('/partner/home')->assertOk();
        $home->assertJsonPath('home.metrics.openLeads', 1)
            ->assertJsonPath('home.metrics.openValue', [['currency' => 'PHP', 'amount_minor' => '150000']])
            ->assertJsonPath('home.metrics.partnerships', 0)
            ->assertJsonPath('home.tasks.reminderTotal', 1)->assertJsonPath('home.tasks.reminders.0.title', 'Own reminder')
            ->assertJsonPath('home.tasks.leadsDue', 1);
        $stages = collect($home->json('home.stages'))->keyBy('value');
        $this->assertSame(['lead', 'pitched', 'negotiating', 'onboarded', 'active', 'repeat-client', 'lost'], $stages->keys()->all());
        $this->assertSame(1, $stages['lost']['count']);
        $this->assertSame([['currency' => 'PHP', 'amount_minor' => '999']], $stages['lost']['totals']);
        $note = collect($home->json('home.activity'))->firstWhere('kind', 'note');
        $this->assertSame('', $note['body']);
        $this->assertStringNotContainsString('Foreign', $home->getContent());
        $this->assertStringNotContainsString('Private rate card', $home->getContent());
    }

    public function test_brand_home_shows_registrations_never_private_pipelines_and_respects_roles(): void
    {
        [$brandUser, $brandOrg] = $this->brand();
        [$partner, $creatorOrg] = $this->creator();
        $relationship = $this->connect($brandOrg, $creatorOrg, 'Cedar Creator');
        $this->member($partner, $creatorOrg)->postJson('/pipeline', ['brand_name' => 'Unshared private prospect', 'currency' => 'PHP', 'value_minor' => '5'])->assertCreated();
        $shared = $this->postJson('/pipeline', ['brand_name' => 'Shared prospect', 'currency' => 'PHP', 'value_minor' => '5'])->assertCreated()->json('id');
        $this->postJson('/lead-registrations', ['partnership' => $relationship, 'entry' => $shared, 'display_name' => 'Harbor Hotel', 'summary' => ''])->assertCreated();
        $this->member($brandUser, $brandOrg)->postJson('/partner-invitations', ['email' => 'pending@example.test'])->assertCreated();

        $home = $this->getJson('/brand/home')->assertOk()
            ->assertJsonPath('home.metrics.activePartners', 1)->assertJsonPath('home.metrics.leadsRegistered', 1)
            ->assertJsonPath('home.tasks.pendingInvitations', 1)->assertJsonPath('home.canManage', true)
            ->assertJsonPath('home.recent.0.name', 'Harbor Hotel')->assertJsonPath('home.recent.0.partner_name', 'Cedar Creator')
            ->assertJsonPath('home.activity.0.event', 'registered');
        $this->assertStringNotContainsString('Unshared private prospect', $home->getContent());
        $this->assertStringNotContainsString('Shared prospect', $home->getContent());
        $this->assertStringNotContainsString('pending@example.test', $home->getContent());
        $this->assertSame(1, collect($home->json('home.stages'))->firstWhere('value', 'lead')['count']);
        $this->assertSame([], collect($home->json('home.stages'))->pluck('totals')->flatten()->all());

        // Roles that cannot read invitations see no figure rather than zero, and no invitation prompt.
        $viewer = $this->join($brandOrg, 'viewer');
        $this->member($viewer, $brandOrg)->getJson('/brand/home')->assertOk()
            ->assertJsonPath('home.tasks.pendingInvitations', null)->assertJsonPath('home.canManage', false)
            ->assertJsonPath('home.metrics.leadsRegistered', 1);

        // Another Brand sees none of it.
        [$otherUser, $otherOrg] = $this->brand();
        $other = $this->member($otherUser, $otherOrg)->getJson('/brand/home')->assertOk()
            ->assertJsonPath('home.metrics.activePartners', 0)->assertJsonPath('home.metrics.leadsRegistered', 0)
            ->assertJsonPath('home.recent', [])->assertJsonPath('home.activity', []);
        $this->assertStringNotContainsString('Harbor Hotel', $other->getContent());

        // Withdrawn registrations leave the Brand Home with the existing visibility rule.
        $registration = $this->control->table('lead_registrations')->where('brand_org_id', $brandOrg)->first();
        $this->member($partner, $creatorOrg)->deleteJson('/lead-registrations/'.$registration->id, ['version' => $registration->version])->assertOk();
        $this->member($brandUser, $brandOrg)->getJson('/brand/home')->assertOk()
            ->assertJsonPath('home.metrics.leadsRegistered', 0)->assertJsonPath('home.recent', []);
    }

    public function test_revoked_and_suspended_access_and_non_members_cannot_open_home(): void
    {
        [$owner, $org] = $this->creator();
        $member = $this->join($org, 'member');
        $this->member($member, $org)->getJson('/partner/home')->assertOk();
        $this->control->table('memberships')->where('org_id', $org)->where('user_id', $member->id)->update(['revoked_at' => now()]);
        $this->member($member, $org)->getJson('/partner/home')->assertForbidden();

        [$outsider] = $this->creator();
        $this->member($outsider, $org)->getJson('/partner/home')->assertForbidden();

        $this->control->table('platform_suspensions')->insert(['subject' => 'workspace', 'org_id' => $org, 'reason' => 'Synthetic suspension', 'suspended_by' => $owner->id]);
        $this->member($owner, $org)->getJson('/partner/home')->assertForbidden();
    }
}
