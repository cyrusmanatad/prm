<?php

namespace Tests\Acceptance;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Authenticator;
use App\Services\LeadRegistrations;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\BrandFixture;

/** Brand lead visibility (D38) against the real runtime role and PostgreSQL row security. */
class BrandLeadVisibilityTest extends AcceptanceCase
{
    private const PASSWORD = 'SyntheticPassword123!';

    private const STAGES = ['lead', 'pitched', 'negotiating', 'onboarded', 'active', 'repeat-client', 'lost'];

    private function brand(string $name = 'Brand'): array
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);

        return [$user, BrandFixture::create($user->id, $name.' '.Str::random(6), $this->control)];
    }

    private function creator(string $name = 'Partner'): array
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);

        return [$user, app(Workspaces::class)->create($user->id, $name.' '.Str::random(6))];
    }

    /** An accepted partnership written by the migration role, as the invitation flow would leave it. */
    private function connect(string $brandOrg, string $creatorOrg, ?string $name = null, ?string $acceptedAt = null): string
    {
        $invitation = (string) Str::uuid();
        $id = (string) Str::uuid();
        $this->control->table('partner_invitations')->insert(['id' => $invitation, 'brand_org_id' => $brandOrg, 'recipient_email' => Str::random(12).'@example.test',
            'token_hash' => hash('sha256', $invitation), 'status' => 'accepted', 'expires_at' => now()->addDay()]);
        $this->control->table('partnerships')->insert(['id' => $id, 'brand_org_id' => $brandOrg, 'creator_org_id' => $creatorOrg, 'brand_name' => 'Brand',
            'creator_name' => $name ?? 'Partner '.Str::random(6), 'invitation_id' => $invitation, 'accepted_at' => $acceptedAt ?? now()->subDay()]);

        return $id;
    }

    private function member(User $user, string $org): static
    {
        return $this->actingAs($user)->withSession(['active_org_id' => $org]);
    }

    private function lead(User $user, string $org, array $extra = []): array
    {
        return $this->member($user, $org)->postJson('/pipeline', ['brand_name' => 'Prospect '.Str::random(5), 'currency' => 'PHP', 'value_minor' => '987654', ...$extra])->assertCreated()->json();
    }

    private function entry(string $id): object
    {
        return $this->control->table('pipeline_entries')->where('id', $id)->first();
    }

    /** A registration written directly, for volume and timestamp cases that the request path cannot produce. */
    private function registration(string $partnership, string $brandOrg, string $creatorOrg, int $actor, array $extra = []): string
    {
        $entry = (string) Str::uuid();
        $id = (string) Str::uuid();
        $at = $extra['registered_at'] ?? now()->subMinutes(5);
        $this->control->table('pipeline_entries')->insert(['id' => $entry, 'org_id' => $creatorOrg, 'brand_name' => 'Seed', 'currency' => 'PHP', 'created_at' => $extra['source_created_at'] ?? now()->subDays(50)]);
        $this->control->table('lead_registrations')->insert(['id' => $id, 'partnership_id' => $partnership, 'brand_org_id' => $brandOrg, 'creator_org_id' => $creatorOrg,
            'pipeline_entry_id' => $entry, 'display_name' => $extra['display_name'] ?? 'Seeded lead', 'stage' => $extra['stage'] ?? 'lead', 'registered_by' => $actor,
            'registered_at' => $at, 'shared_updated_at' => $extra['shared_updated_at'] ?? $at]);

        return $id;
    }

    private function suspend(string $org, User $by): void
    {
        $this->control->table('platform_suspensions')->insert(['subject' => 'workspace', 'org_id' => $org, 'reason' => 'Synthetic suspension', 'suspended_by' => $by->id]);
    }

    private function probe2(string $action, User $actor, string $org, string $partnership, string $entry = ''): Process
    {
        $process = new Process([PHP_BINARY, 'tests/Fixtures/lead-registration-probe.php', $action, (string) $actor->id, $org, $partnership, $entry], base_path(), ['APP_ENV' => 'testing', 'TEST_MIGRATION_DB_PASSWORD' => false]);
        $process->setTimeout(15);
        $process->start();
        $this->eventually(fn () => str_contains($process->getOutput(), "\n"), 'Probe failed to connect: '.$process->getErrorOutput());

        return $process;
    }

    public function test_a_lead_stays_private_until_an_eligible_partner_role_registers_it_once(): void
    {
        [$brandUser, $brand] = $this->brand();
        [$otherBrandUser, $otherBrand] = $this->brand('Other');
        [$partner, $org] = $this->creator();
        [$stranger, $strangerOrg] = $this->creator('Stranger');
        $relationship = $this->connect($brand, $org, 'Visible Partner');
        $foreignRelationship = $this->connect($otherBrand, $strangerOrg);
        $lead = $this->lead($partner, $org, ['brand_name' => 'Private prospect company', 'contact_name' => 'Casey Contact', 'contact_email' => 'casey@example.test', 'notes' => 'Private rates 45000'])['id'];
        $foreignLead = $this->lead($stranger, $strangerOrg)['id'];

        // Nothing is shared by having a partnership or a lead.
        $this->member($brandUser, $brand)->getJson('/brand')->assertOk()->assertJsonPath('metrics.leadsRegistered', 0)->assertJsonPath('metrics.activePartners', 1)
            ->assertJsonPath('partners.0.leads_30d', 0)->assertJsonPath('partners.0.leads_total', 0)->assertJsonPath('partners.0.last_active', null)->assertDontSee('Private prospect company');
        $this->getJson('/brand/lead-registrations')->assertOk()->assertJsonCount(0, 'registrations')->assertJsonPath('pagination.total', 0);

        $payload = ['partnership' => $relationship, 'entry' => $lead, 'display_name' => 'Registered lead name', 'summary' => 'Wholesale interest'];
        foreach (['viewer', 'member', 'manager'] as $role) {
            $this->control->table('memberships')->where('org_id', $org)->update(['role' => $role]);
            $this->member($partner, $org)->postJson('/lead-registrations', $payload)->assertForbidden();
            $this->putJson('/partner-shared-profile', ['version' => 0, 'description' => 'Denied'])->assertForbidden();
        }
        $this->control->table('memberships')->where('org_id', $org)->update(['role' => 'admin']);
        // Forged identifiers: another workspace's lead or partnership, and a missing name.
        $this->postJson('/lead-registrations', [...$payload, 'entry' => $foreignLead])->assertNotFound();
        $this->postJson('/lead-registrations', [...$payload, 'partnership' => $foreignRelationship])->assertUnprocessable()->assertJsonValidationErrors('partnership');
        $this->postJson('/lead-registrations', [...$payload, 'display_name' => ''])->assertUnprocessable();
        $this->assertSame(0, $this->control->table('lead_registrations')->count() - $this->control->table('lead_registrations')->where('creator_org_id', '<>', $org)->count());

        $id = $this->postJson('/lead-registrations', $payload)->assertCreated()->assertJsonPath('status', 'created')->json('id');
        $first = $this->control->table('lead_registrations')->where('id', $id)->first();
        // A retried request changes nothing, including the first registration time.
        $this->postJson('/lead-registrations', [...$payload, 'display_name' => 'Retried'])->assertOk()->assertJsonPath('status', 'existing')->assertJsonPath('id', $id);
        $this->assertSame(1, $this->control->table('lead_registrations')->where('pipeline_entry_id', $lead)->count());
        $again = $this->control->table('lead_registrations')->where('id', $id)->first();
        $this->assertSame([$first->registered_at, $first->display_name, $first->version], [$again->registered_at, $again->display_name, $again->version]);
        $this->assertSame(1, $this->control->table('audit_logs')->where('org_id', $org)->where('event', 'lead_registration.registered')->where('subject_id', $id)->count());

        // The Brand reads the allowlist only.
        $this->member($brandUser, $brand)->getJson('/brand')->assertJsonPath('metrics.leadsRegistered', 1)->assertJsonPath('partners.0.leads_30d', 1)->assertJsonPath('partners.0.last_active.kind', 'lead_registered');
        $list = $this->getJson('/brand/lead-registrations')->assertOk()->assertJsonCount(1, 'registrations')->assertJsonPath('pagination.total', 1);
        $this->assertSame(['display_name', 'id', 'partner_name', 'partnership_id', 'registered_at', 'shared_updated_at', 'stage'], collect($list->json('registrations.0'))->keys()->sort()->values()->all());
        $detail = $this->getJson('/brand/lead-registrations/'.$id)->assertOk()->assertJsonPath('registration.display_name', 'Registered lead name')->assertJsonPath('registration.summary', 'Wholesale interest')
            ->assertJsonPath('registration.stage', 'lead')->assertJsonPath('registration.partner_name', 'Visible Partner')->assertJsonPath('registration.share_contact', false)
            ->assertJsonPath('registration.contact_name', null)->assertJsonPath('registration.contact_email', null);
        foreach (['Private rates', '987654', 'casey@example.test', 'Casey Contact', 'Private prospect company', $lead, $partner->email, 'registered_by', 'notes', 'value_minor'] as $private) {
            $detail->assertDontSee($private);
            $list->assertDontSee($private);
        }
        $this->getJson('/brand/partners/'.$relationship)->assertOk()->assertJsonPath('partner.leads_total', 1)->assertJsonCount(1, 'registrations')->assertDontSee($partner->email)->assertDontSee('Private rates');
        // Inertia responses, including partial reloads, carry the same projection.
        $this->withoutVite();
        $this->get('/brand/lead-registrations/'.$id)->assertOk()->assertDontSee('Private rates')->assertDontSee('casey@example.test')->assertSee('Registered lead name');
        $this->get('/brand/lead-registrations', ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '', 'X-Inertia-Partial-Component' => 'BrandLeadRegistrations', 'X-Inertia-Partial-Data' => 'registrations'])
            ->assertOk()->assertJsonCount(1, 'props.registrations')->assertDontSee('Private rates')->assertDontSee($lead);

        // A Brand gains no rights over the source, and cannot publish.
        $this->patchJson('/pipeline/'.$lead, ['brand_name' => 'Hijack', 'currency' => 'PHP', 'value_minor' => '1', 'version' => 1])->assertNotFound();
        $this->postJson('/pipeline/'.$lead.'/stage', ['stage' => 'lost', 'version' => 1])->assertNotFound();
        $this->postJson('/lead-registrations', $payload)->assertUnprocessable();
        $this->patchJson('/lead-registrations/'.$id, ['version' => 1, 'display_name' => 'Brand edit'])->assertNotFound();
        $this->deleteJson('/lead-registrations/'.$id, ['version' => 1])->assertNotFound();
        $this->get('/partner/brand-sharing')->assertRedirect('/workspaces');

        // Another Brand, another Partner, a viewer of the Brand, a revoked member and an anonymous caller.
        $this->member($otherBrandUser, $otherBrand)->getJson('/brand')->assertJsonPath('metrics.leadsRegistered', 0);
        $this->getJson('/brand/lead-registrations')->assertJsonCount(0, 'registrations');
        $this->getJson('/brand/lead-registrations?partner='.$relationship)->assertOk()->assertJsonCount(0, 'registrations')->assertJsonPath('pagination.total', 0);
        $this->getJson('/brand/lead-registrations/'.$id)->assertNotFound();
        $this->getJson('/brand/partners/'.$relationship)->assertNotFound();
        $this->member($stranger, $strangerOrg)->getJson('/partner/brand-sharing')->assertOk()->assertJsonCount(0, 'registrations');
        $this->getJson('/partner/brand-sharing?lead='.$lead)->assertNotFound();
        $this->patchJson('/lead-registrations/'.$id, ['version' => 1, 'display_name' => 'Stolen'])->assertNotFound();
        $this->deleteJson('/lead-registrations/'.$id, ['version' => 1])->assertNotFound();
        $this->getJson('/brand/lead-registrations')->assertRedirect('/workspaces');
        $this->control->table('memberships')->where('org_id', $brand)->update(['role' => 'viewer']);
        $this->member($brandUser, $brand)->getJson('/brand/lead-registrations/'.$id)->assertOk();
        $this->control->table('memberships')->where('org_id', $brand)->update(['revoked_at' => now()]);
        $this->getJson('/brand/lead-registrations')->assertForbidden();
        $this->getJson('/brand/lead-registrations/'.$id)->assertForbidden();
        $this->control->table('memberships')->where('org_id', $brand)->update(['revoked_at' => null, 'role' => 'owner']);
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->getJson('/brand/lead-registrations')->assertUnauthorized();
        $this->getJson('/brand/lead-registrations/'.$id)->assertUnauthorized();

        // Under the Brand's own context the runtime role still cannot read private tables or write shared ones.
        app(TenantContext::class)->run($brandUser->id, $brand, function (): void {
            $this->assertSame(0, DB::table('pipeline_entries')->count());
            $this->assertSame(0, DB::table('pipeline_activities')->count());
            $this->assertSame(0, DB::table('partner_shared_profiles')->count());
            $this->assertSame(1, DB::table('lead_registrations')->count());
            $this->assertFalse(DB::selectOne('SELECT app_record_workspace_sign_in() AS recorded')->recorded);
        });
        foreach ([
            "UPDATE lead_registrations SET display_name='x'", 'DELETE FROM lead_registrations', 'SELECT * FROM workspace_sign_ins',
            "INSERT INTO workspace_sign_ins(org_id,signed_in_at) VALUES('$org',now())", "UPDATE partner_shared_profiles SET description='x'",
        ] as $statement) {
            try {
                app(TenantContext::class)->run($brandUser->id, $brand, fn () => DB::statement($statement));
                $this->fail('Runtime role executed: '.$statement);
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->getCode());
            }
        }
    }

    public function test_source_changes_update_only_shared_fields_and_roll_back_together(): void
    {
        [$brandUser, $brand] = $this->brand();
        [$partner, $org] = $this->creator();
        $relationship = $this->connect($brand, $org);
        $lead = $this->lead($partner, $org, ['contact_name' => 'First Contact', 'contact_email' => 'first@example.test', 'notes' => 'Old note'])['id'];
        $hidden = $this->postJson('/lead-registrations', ['partnership' => $relationship, 'entry' => $lead, 'display_name' => 'Without contact'])->assertCreated()->json('id');
        $other = $this->lead($partner, $org)['id'];
        $shared = $this->postJson('/lead-registrations', ['partnership' => $relationship, 'entry' => $other, 'display_name' => 'With contact', 'share_contact' => true])->assertCreated()->json('id');
        $state = fn (string $id) => $this->control->table('lead_registrations')->where('id', $id)->first();
        $before = $state($hidden);

        // Private note, value and next-action edits never reach the registration or its activity time.
        $this->patchJson('/pipeline/'.$lead, ['brand_name' => 'Renamed privately', 'notes' => 'New private note', 'currency' => 'PHP', 'value_minor' => '5', 'version' => 1])->assertOk();
        $this->postJson('/pipeline/'.$lead.'/activities', ['version' => 2, 'kind' => 'note', 'body' => 'Private activity'])->assertCreated();
        $this->assertEquals($before, $state($hidden));
        // A contact change is shared only where the Partner opted in.
        $this->patchJson('/pipeline/'.$lead, ['brand_name' => 'Renamed privately', 'contact_name' => 'Second Contact', 'currency' => 'PHP', 'value_minor' => '5', 'version' => 3])->assertOk();
        $this->assertEquals($before, $state($hidden));
        $this->patchJson('/pipeline/'.$other, ['brand_name' => 'Other', 'contact_name' => 'Opted In', 'contact_email' => 'opted@example.test', 'currency' => 'PHP', 'value_minor' => '5', 'version' => 1])->assertOk();
        $this->assertSame(['Opted In', 'opted@example.test', 'updated'], [$state($shared)->contact_name, $state($shared)->contact_email, $state($shared)->last_event]);

        // A stage move appears without re-registration and does not move the registration date.
        foreach (self::STAGES as $index => $stage) {
            $this->postJson('/pipeline/'.$lead.'/stage', ['stage' => $stage, 'version' => 4 + $index])->assertOk();
            $this->assertSame($stage, $state($hidden)->stage);
        }
        $moved = $state($hidden);
        $this->assertSame($before->registered_at, $moved->registered_at);
        $this->assertTrue($moved->shared_updated_at > $before->shared_updated_at);
        $this->member($brandUser, $brand)->getJson('/brand/lead-registrations/'.$hidden)->assertJsonPath('registration.stage', 'lost')->assertDontSee('New private note')->assertDontSee('Renamed privately')->assertDontSee('Second Contact');
        $this->getJson('/brand/lead-registrations/'.$shared)->assertJsonPath('registration.contact_email', 'opted@example.test');
        $this->getJson('/brand/lead-registrations?stage=lost')->assertJsonCount(1, 'registrations');
        $this->getJson('/brand')->assertJsonPath('partners.0.last_active.kind', 'lead_updated');

        // A stale or failed source write leaves both the lead and its registration unchanged.
        $this->member($partner, $org)->postJson('/pipeline/'.$lead.'/stage', ['stage' => 'active', 'version' => 1])->assertConflict();
        try {
            app(TenantContext::class)->run($partner->id, $org, function () use ($lead): void {
                DB::table('pipeline_entries')->where('id', $lead)->update(['stage' => 'active']);
                $this->assertSame('active', DB::table('lead_registrations')->where('pipeline_entry_id', $lead)->value('stage'));
                throw new RuntimeException('Fail after the shared write.');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(['lost', 'lost'], [$this->entry($lead)->stage, $state($hidden)->stage]);

        // Partner edits of the shared copy are versioned.
        $version = $state($hidden)->version;
        $this->patchJson('/lead-registrations/'.$hidden, ['version' => $version + 5, 'display_name' => 'Stale'])->assertConflict();
        $this->patchJson('/lead-registrations/'.$hidden, ['version' => $version, 'display_name' => 'Edited name', 'summary' => 'Edited summary', 'share_contact' => true])->assertOk();
        $this->assertSame(['Edited name', 'Edited summary', 'Second Contact'], [$state($hidden)->display_name, $state($hidden)->summary, $state($hidden)->contact_name]);
        $this->assertSame('Renamed privately', $this->entry($lead)->brand_name);
    }

    public function test_withdrawal_source_deletion_ending_and_suspension_remove_brand_access(): void
    {
        [$brandUser, $brand] = $this->brand();
        [$partner, $org] = $this->creator();
        $relationship = $this->connect($brand, $org);
        $state = fn (string $id) => $this->control->table('lead_registrations')->where('id', $id)->first();
        $register = fn (string $entry, string $to) => $this->member($partner, $org)->postJson('/lead-registrations', ['partnership' => $to, 'entry' => $entry, 'display_name' => 'Lifecycle lead', 'summary' => 'Shared summary', 'share_contact' => true]);
        $visible = fn () => $this->member($brandUser, $brand)->getJson('/brand')->json('metrics.leadsRegistered');
        $first = $this->lead($partner, $org, ['contact_email' => 'lifecycle@example.test'])['id'];
        $second = $this->lead($partner, $org)['id'];
        $third = $this->lead($partner, $org)['id'];
        $a = $register($first, $relationship)->assertCreated()->json('id');
        $b = $register($second, $relationship)->assertCreated()->json('id');
        $c = $register($third, $relationship)->assertCreated()->json('id');
        $this->assertSame(3, $visible());

        // Withdrawal is versioned, audited and immediate. Re-sharing keeps the first registration time.
        $registeredAt = $state($a)->registered_at;
        $this->member($partner, $org)->deleteJson('/lead-registrations/'.$a, ['version' => 9])->assertConflict();
        $this->deleteJson('/lead-registrations/'.$a, ['version' => 1])->assertOk();
        $this->deleteJson('/lead-registrations/'.$a, ['version' => 2])->assertNotFound();
        $this->assertSame(1, $this->control->table('audit_logs')->where('event', 'lead_registration.withdrawn')->where('subject_id', $a)->count());
        $this->assertSame(2, $visible());
        $this->getJson('/brand/lead-registrations/'.$a)->assertNotFound();
        $this->getJson('/brand/lead-registrations')->assertJsonCount(2, 'registrations');
        $this->assertNotNull($this->entry($first));
        $register($first, $relationship)->assertOk()->assertJsonPath('status', 'reshared')->assertJsonPath('id', $a);
        $this->assertSame([$registeredAt, 'active', 'updated'], [$state($a)->registered_at, $state($a)->state, $state($a)->last_event]);
        $this->assertSame(3, $visible());

        // Deleting the private lead withdraws its registration and clears the shared copy; a failed delete changes neither.
        $this->member($partner, $org)->deleteJson('/pipeline/'.$second, ['version' => 9])->assertConflict();
        try {
            app(TenantContext::class)->run($partner->id, $org, function () use ($second, $b): void {
                DB::table('pipeline_entries')->where('id', $second)->delete();
                $this->assertSame('withdrawn', DB::table('lead_registrations')->where('id', $b)->value('state'));
                throw new RuntimeException('Fail after the delete.');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(['active', $second], [$state($b)->state, $state($b)->pipeline_entry_id]);
        $this->assertNotNull($this->entry($second));
        $this->deleteJson('/pipeline/'.$second, ['version' => 1])->assertOk();
        $deleted = $state($b);
        $this->assertSame(['withdrawn', 'source_deleted', null, 'Deleted lead', '', null], [$deleted->state, $deleted->withdrawn_reason, $deleted->pipeline_entry_id, $deleted->display_name, $deleted->summary, $deleted->contact_email]);
        $this->assertSame(2, $visible());
        $this->getJson('/brand/lead-registrations/'.$b)->assertNotFound();

        // A suspended Partner is shown as paused with no figures; its registrations and profile are not readable.
        $this->member($partner, $org)->putJson('/partner-shared-profile', ['version' => 0, 'contact_name' => 'Profile Person', 'description' => 'Shared description'])->assertOk();
        $this->member($brandUser, $brand)->getJson('/brand/partners/'.$relationship)->assertJsonPath('partner.profile.contact_name', 'Profile Person')->assertJsonPath('partner.leads_total', 2);
        $this->suspend($org, $brandUser);
        $this->getJson('/brand')->assertJsonPath('metrics.leadsRegistered', 0)->assertJsonPath('metrics.activePartners', 1)->assertJsonPath('partners.0.paused', true)
            ->assertJsonPath('partners.0.leads_30d', null)->assertJsonPath('partners.0.leads_total', null)->assertJsonPath('partners.0.last_active', null)->assertDontSee('Synthetic suspension');
        $this->getJson('/brand/lead-registrations')->assertJsonCount(0, 'registrations')->assertJsonPath('pagination.total', 0);
        $this->getJson('/brand/lead-registrations/'.$a)->assertNotFound();
        $this->getJson('/brand/partners/'.$relationship)->assertOk()->assertJsonPath('partner.paused', true)->assertJsonPath('partner.profile', null)->assertJsonCount(0, 'registrations')->assertDontSee('Profile Person')->assertDontSee('Synthetic suspension');
        $this->control->table('platform_suspensions')->where('org_id', $org)->update(['lifted_at' => now(), 'lifted_by' => $brandUser->id]);
        $this->assertSame(2, $visible());
        // A suspended Brand cannot be registered with, and its Partner sees it as paused.
        $this->suspend($brand, $brandUser);
        $fourth = $this->lead($partner, $org)['id'];
        $register($fourth, $relationship)->assertUnprocessable()->assertJsonValidationErrors('partnership');
        $this->getJson('/partner/brand-sharing')->assertJsonPath('brands.0.paused', true);
        // Withdrawal stays possible while the Brand is suspended.
        $this->deleteJson('/lead-registrations/'.$c, ['version' => 1])->assertOk();
        $this->control->table('platform_suspensions')->where('org_id', $brand)->update(['lifted_at' => now(), 'lifted_by' => $brandUser->id]);
        $this->assertSame(1, $visible());

        // Ending the partnership ends its registrations. A new partnership starts with none and never revives them.
        $this->member($brandUser, $brand)->deleteJson('/partnerships/'.$relationship)->assertOk();
        $this->assertSame(['withdrawn', 'partnership_ended'], [$state($a)->state, $state($a)->withdrawn_reason]);
        $this->getJson('/brand')->assertJsonPath('metrics.leadsRegistered', 0)->assertJsonPath('metrics.activePartners', 0)->assertJsonCount(0, 'partners');
        $this->getJson('/brand/lead-registrations/'.$a)->assertNotFound();
        $this->getJson('/brand/partners/'.$relationship)->assertNotFound();
        $this->getJson('/brand/lead-registrations?partner='.$relationship)->assertJsonCount(0, 'registrations');
        $register($first, $relationship)->assertUnprocessable();
        $this->patchJson('/lead-registrations/'.$a, ['version' => $state($a)->version, 'display_name' => 'After end'])->assertNotFound();
        $this->assertNotNull($this->entry($first));
        $rejoined = $this->connect($brand, $org);
        $this->assertSame(0, $visible());
        $this->getJson('/brand/partners/'.$rejoined)->assertOk()->assertJsonPath('partner.leads_total', 0)->assertJsonCount(0, 'registrations');
        $fresh = $register($first, $rejoined)->assertCreated()->json('id');
        $this->assertNotSame($a, $fresh);
        $this->assertSame(1, $visible());
        // The Partner still sees its own history, without the ended Brand's name.
        $this->member($partner, $org)->getJson('/partner/brand-sharing')->assertOk()->assertJsonPath('pagination.total', 4);
    }

    public function test_counts_use_one_visibility_rule_window_and_clock(): void
    {
        [$brandUser, $brand] = $this->brand();
        [$otherBrandUser, $otherBrand] = $this->brand('Other');
        [$partner, $org] = $this->creator();
        [$second, $secondOrg] = $this->creator('Second');
        $one = $this->connect($brand, $org, 'Alpha', now()->subDays(3)->toIso8601String());
        $two = $this->connect($brand, $secondOrg, 'Beta', now()->subDays(2)->toIso8601String());
        $elsewhere = $this->connect($otherBrand, $org);
        $now = Carbon::parse('2026-11-18T12:00:00.000000Z');
        $at = fn (Carbon $moment) => $moment->format('Y-m-d H:i:s.uP');
        // Old source lead with a recent registration, the exact lower boundary, just outside it, and a future timestamp.
        $this->registration($one, $brand, $org, $partner->id, ['registered_at' => $at($now->copy()->subDays(2)), 'source_created_at' => $at($now->copy()->subDays(60))]);
        $this->registration($one, $brand, $org, $partner->id, ['registered_at' => $at($now->copy()->subHours(720))]);
        $this->registration($one, $brand, $org, $partner->id, ['registered_at' => $at($now->copy()->subHours(720)->subMicrosecond())]);
        $this->registration($one, $brand, $org, $partner->id, ['registered_at' => $at($now->copy()->addSecond())]);
        // Every stage counts; another Partner and another Brand do not leak into this Partner's figures.
        foreach (self::STAGES as $stage) {
            $this->registration($two, $brand, $secondOrg, $second->id, ['registered_at' => $at($now->copy()->subDays(40)), 'stage' => $stage]);
        }
        $this->registration($elsewhere, $otherBrand, $org, $partner->id, ['registered_at' => $at($now->copy()->subDay())]);
        $withdrawn = $this->registration($one, $brand, $org, $partner->id, ['registered_at' => $at($now->copy()->subDay())]);
        $this->control->table('lead_registrations')->where('id', $withdrawn)->update(['state' => 'withdrawn', 'withdrawn_at' => now(), 'withdrawn_reason' => 'partner']);

        $leads = app(LeadRegistrations::class);
        app(TenantContext::class)->run($brandUser->id, $brand, function () use ($leads, $brand, $one, $two, $now, $at): void {
            $clock = $at($now);
            $roster = collect($leads->roster($brand, '', 1, $clock)['rows'])->keyBy('id');
            $this->assertSame([4, 2], [$roster[$one]['leads_total'], $roster[$one]['leads_30d']]);
            $this->assertSame([7, 0], [$roster[$two]['leads_total'], $roster[$two]['leads_30d']]);
            // The row link's filtered list has exactly the row's count, and the card equals the unfiltered total.
            $this->assertSame(2, $leads->listing($brand, ['partner' => $one, 'window' => '30d'], 1, $clock)['total']);
            $this->assertSame(0, $leads->listing($brand, ['partner' => $two, 'window' => '30d'], 1, $clock)['total']);
            $this->assertSame(11, $leads->metrics($brand)['leadsRegistered']);
            $this->assertSame(11, $leads->listing($brand, [], 1, $clock)['total']);
            foreach (self::STAGES as $stage) {
                $this->assertSame($stage === 'lead' ? 5 : 1, $leads->listing($brand, ['stage' => $stage], 1, $clock)['total']);
            }
        });
        $this->member($brandUser, $brand)->getJson('/brand')->assertJsonPath('metrics.leadsRegistered', 11)->assertJsonPath('metrics.activePartners', 2);
        $this->member($otherBrandUser, $otherBrand)->getJson('/brand')->assertJsonPath('metrics.leadsRegistered', 1)->assertJsonPath('partners.0.leads_total', 1);

        // Lists page in the database; the card never shrinks to a page length.
        for ($i = 0; $i < 20; $i++) {
            $this->registration($two, $brand, $secondOrg, $second->id, ['display_name' => 'Bulk '.$i, 'registered_at' => now()->subMinutes(10 + $i)]);
        }
        $this->member($brandUser, $brand)->getJson('/brand')->assertJsonPath('metrics.leadsRegistered', 31);
        $first = $this->getJson('/brand/lead-registrations')->assertJsonPath('pagination.total', 31)->assertJsonPath('pagination.perPage', 25)->assertJsonCount(25, 'registrations')->json('registrations');
        $last = $this->getJson('/brand/lead-registrations?page=2')->assertJsonPath('pagination.total', 31)->assertJsonCount(6, 'registrations')->json('registrations');
        $this->assertCount(31, array_unique(array_column([...$first, ...$last], 'id')));
        $this->getJson('/brand/lead-registrations?page=9')->assertJsonPath('pagination.page', 2)->assertJsonCount(6, 'registrations');
        $this->getJson('/brand/lead-registrations?q=bulk 1')->assertJsonPath('pagination.total', 11);
        $this->getJson('/brand/lead-registrations?q=%25')->assertJsonPath('pagination.total', 0);
        $this->getJson('/brand/lead-registrations?stage=won')->assertUnprocessable();
        $this->getJson('/brand/lead-registrations?window=7d')->assertUnprocessable();
        $this->getJson('/brand/lead-registrations?partner=not-a-uuid')->assertUnprocessable();
        // Page size, sort order and the counts behind the stage and partner choices.
        $this->getJson('/brand/lead-registrations?per_page=10')->assertJsonPath('pagination.perPage', 10)->assertJsonCount(10, 'registrations')->assertJsonPath('filters.per_page', '10');
        $this->getJson('/brand/lead-registrations?per_page=7')->assertUnprocessable();
        $this->getJson('/brand/lead-registrations?sort=newest')->assertUnprocessable();
        $byName = array_column($this->getJson('/brand/lead-registrations?sort=lead_asc&per_page=50')->assertJsonCount(31, 'registrations')->json('registrations'), 'display_name');
        $sorted = $byName;
        usort($sorted, fn ($a, $b) => strcmp(mb_strtolower($a), mb_strtolower($b)));
        $this->assertSame($sorted, $byName);
        $this->assertSame(array_reverse($byName), array_column($this->getJson('/brand/lead-registrations?sort=lead_desc&per_page=50')->json('registrations'), 'display_name'));
        $oldest = array_column($this->getJson('/brand/lead-registrations?sort=registered_asc&per_page=50')->json('registrations'), 'id');
        $this->assertSame(array_reverse(array_column([...$first, ...$last], 'id')), $oldest);
        $facets = $this->getJson('/brand/lead-registrations?stage=lead&partner='.$two)->json('facets');
        // Each facet ignores only its own filter, so its counts match the list choosing it would show.
        $this->assertSame($this->getJson('/brand/lead-registrations?partner='.$two)->json('pagination.total'), $facets['stageTotal']);
        $this->assertSame($this->getJson('/brand/lead-registrations?stage=lead')->json('pagination.total'), $facets['partnerTotal']);
        $this->assertSame($this->getJson('/brand/lead-registrations?stage=lead&partner='.$two)->json('pagination.total'), $facets['stages']['lead']);
        $this->assertSame($facets['stages']['lead'], $facets['partners'][$two]);
        $this->assertSame($facets['stageTotal'], array_sum($facets['stages']));
        $this->getJson('/brand/partners/'.$two.'?page=2')->assertJsonPath('pagination.total', 27)->assertJsonPath('pagination.perPage', 10)->assertJsonCount(10, 'registrations');
    }

    public function test_the_partner_roster_pages_in_a_stable_order_with_full_result_totals(): void
    {
        [$brandUser, $brand] = $this->brand();
        $this->member($brandUser, $brand)->getJson('/brand')->assertJsonPath('metrics.activePartners', 0)->assertJsonCount(0, 'partners')->assertJsonPath('partnerPagination.total', 0)->assertJsonPath('metrics.leadsRegistered', 0);
        $ids = [];
        $accepted = now()->subDays(5)->toIso8601String();
        foreach ([1, 10, 11, 13] as $size) {
            while (count($ids) < $size) {
                [, $org] = $this->creator();
                // Identical acceptance times: the unique ID decides the order.
                $ids[] = $this->connect($brand, $org, 'Roster '.str_pad((string) count($ids), 2, '0', STR_PAD_LEFT), $accepted);
            }
            $first = $this->getJson('/brand')->assertJsonPath('metrics.activePartners', $size)->assertJsonPath('partnerPagination.total', $size)->assertJsonPath('partnerPagination.perPage', 10)
                ->assertJsonCount(min($size, 10), 'partners')->assertJsonCount(min($size, 10), 'relationships')->json('partners');
            $second = $this->getJson('/brand?page=2')->assertJsonPath('metrics.activePartners', $size)->assertJsonPath('partnerPagination.total', $size)->json('partners');
            $seen = array_column($size > 10 ? [...$first, ...$second] : $first, 'id');
            $sorted = $ids;
            sort($sorted);
            $this->assertSame($sorted, $seen);
            $this->assertSame($seen, array_column($size > 10 ? [...$this->getJson('/brand')->json('partners'), ...$this->getJson('/brand?page=2')->json('partners')] : $this->getJson('/brand')->json('partners'), 'id'));
        }
        // Search narrows the roster and its count but not the global figures; a page past the end falls back.
        $this->getJson('/brand?q=roster 1')->assertJsonPath('partnerPagination.total', 3)->assertJsonCount(3, 'partners')->assertJsonPath('metrics.activePartners', 13)->assertJsonPath('partnerFilters.q', 'roster 1');
        $this->getJson('/brand?q=_')->assertJsonPath('partnerPagination.total', 0)->assertJsonCount(0, 'partners')->assertJsonPath('metrics.activePartners', 13);
        $this->getJson('/brand?page=7')->assertJsonPath('partnerPagination.page', 2)->assertJsonCount(3, 'partners');
        // Ending the only partnerships of the last page leaves a valid last page.
        sort($ids);
        foreach (array_slice($ids, 10) as $id) {
            $this->deleteJson('/partnerships/'.$id)->assertOk();
        }
        $this->getJson('/brand?page=2')->assertJsonPath('partnerPagination.page', 1)->assertJsonPath('partnerPagination.total', 10)->assertJsonCount(10, 'partners')->assertJsonPath('metrics.activePartners', 10);
        $this->getJson('/brand?page=0')->assertUnprocessable();
    }

    public function test_last_active_counts_only_completed_partner_sign_ins_to_the_entered_workspace(): void
    {
        [$brandUser, $brand] = $this->brand();
        [$partner, $org] = $this->creator();
        $other = app(Workspaces::class)->create($partner->id, 'Second workspace');
        $relationship = $this->connect($brand, $org);
        $otherRelationship = $this->connect($brand, $other, 'Unentered');
        $signedIn = fn (string $workspace) => $this->control->table('workspace_sign_ins')->where('org_id', $workspace)->value('signed_in_at');
        $leave = function (): void {
            $this->flushSession();
            $this->app['auth']->forgetGuards();
        };
        $activity = fn () => collect($this->member($brandUser, $brand)->getJson('/brand')->json('partners'))->keyBy('id');
        $this->assertNull($activity()[$relationship]['last_active']);

        // A request without a sign-in, a failed password and a suspended account record nothing.
        $this->member($partner, $org)->getJson('/partner/leads')->assertOk();
        $this->assertNull($signedIn($org));
        $leave();
        $this->post('/partner/login', ['email' => $partner->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->control->table('platform_suspensions')->insert(['subject' => 'account', 'user_id' => $partner->id, 'reason' => 'Synthetic suspension', 'suspended_by' => $brandUser->id]);
        $this->post('/partner/login', ['email' => $partner->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->control->table('platform_suspensions')->where('user_id', $partner->id)->update(['lifted_at' => now(), 'lifted_by' => $brandUser->id]);
        $this->assertNull($signedIn($org));

        // A completed sign-in is credited once, to the workspace it enters, and later requests do not move it.
        $this->post('/partner/login', ['email' => $partner->email, 'password' => self::PASSWORD])->assertRedirect('/portal/enter');
        $this->assertNull($signedIn($org));
        $this->withSession(['active_org_id' => $org])->getJson('/partner/leads')->assertOk();
        $first = $signedIn($org);
        $this->assertNotNull($first);
        $this->getJson('/partner/leads')->assertOk();
        $this->withSession(['active_org_id' => $other])->getJson('/partner/leads')->assertOk();
        $this->assertSame($first, $signedIn($org));
        $this->assertNull($signedIn($other));
        $leave();
        $rows = $activity();
        $this->assertSame('signed_in', $rows[$relationship]['last_active']['kind']);
        $this->assertNull($rows[$otherRelationship]['last_active']);
        $this->getJson('/brand')->assertDontSee($partner->email)->assertDontSee('session');

        // A Brand sign-in is never recorded, even for an account that also has a Partner workspace.
        $leave();
        $this->post('/brand/login', ['email' => $brandUser->email, 'password' => self::PASSWORD])->assertRedirect('/portal/enter');
        $this->withSession(['active_org_id' => $brand])->getJson('/brand')->assertOk();
        $this->assertNull($signedIn($brand));
        $leave();

        // With owner MFA enforced, a pending or failed check records nothing; a passed check or recovery code does.
        config(['security.owner_mfa' => true]);
        $this->control->table('workspace_sign_ins')->delete();
        $secret = $this->actingAs($partner)->postJson('/account/authenticator/enroll', ['password' => self::PASSWORD])->assertOk()->json('secret');
        $authenticator = app(Authenticator::class);
        $codes = $this->postJson('/account/authenticator/verify', ['code' => $authenticator->code($secret, intdiv(time(), 30))])->assertOk()->json('recoveryCodes');
        $this->control->table('users')->where('id', $partner->id)->update(['admin_totp_counter' => null]);
        $leave();
        $this->post('/partner/login', ['email' => $partner->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->withSession(['active_org_id' => $org])->getJson('/partner/leads')->assertForbidden();
        $this->postJson('/account/authenticator/verify', ['code' => '000000'])->assertUnprocessable();
        $this->getJson('/partner/leads')->assertForbidden();
        $this->assertNull($signedIn($org));
        $this->postJson('/account/authenticator/verify', ['code' => $authenticator->code($secret, intdiv(time(), 30))])->assertOk();
        $this->withSession(['active_org_id' => $org])->getJson('/partner/leads')->assertOk();
        $this->assertNotNull($signedIn($org));
        $leave();
        $this->control->table('workspace_sign_ins')->delete();
        $this->post('/partner/login', ['email' => $partner->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->postJson('/account/authenticator/recover', ['recovery_code' => 'AAAAA-AAAAA'])->assertUnprocessable();
        $this->assertNull($signedIn($org));
        $this->postJson('/account/authenticator/recover', ['recovery_code' => $codes[0]])->assertOk();
        $this->withSession(['active_org_id' => $org])->getJson('/partner/leads')->assertOk();
        $this->assertNotNull($signedIn($org));
        $leave();
        config(['security.owner_mfa' => false]);

        // Shared lead activity outranks an older sign-in; another Brand's leads and private edits never move this Brand's signal.
        [$elseUser, $elseBrand] = $this->brand('Elsewhere');
        $elsewhere = $this->connect($elseBrand, $org);
        $lead = $this->lead($partner, $org)['id'];
        $this->member($partner, $org)->postJson('/lead-registrations', ['partnership' => $elsewhere, 'entry' => $lead, 'display_name' => 'For the other Brand'])->assertCreated();
        $this->postJson('/pipeline/'.$lead.'/stage', ['stage' => 'pitched', 'version' => 1])->assertOk();
        $this->assertSame('signed_in', $activity()[$relationship]['last_active']['kind']);
        $this->member($partner, $org)->postJson('/lead-registrations', ['partnership' => $relationship, 'entry' => $lead, 'display_name' => 'For this Brand'])->assertCreated();
        $registered = $activity()[$relationship]['last_active'];
        $this->assertSame('lead_registered', $registered['kind']);
        $this->member($partner, $org)->patchJson('/pipeline/'.$lead, ['brand_name' => 'Private rename', 'notes' => 'Private', 'currency' => 'PHP', 'value_minor' => '1', 'version' => 2])->assertOk();
        $this->assertSame($registered, $activity()[$relationship]['last_active']);
        // A sign-in from before the partnership was accepted is not disclosed, and an exact tie reports the lead event.
        $this->control->table('lead_registrations')->where('partnership_id', $relationship)->delete();
        $this->control->table('workspace_sign_ins')->where('org_id', $org)->update(['signed_in_at' => now()->subDays(9)]);
        $this->assertNull($activity()[$relationship]['last_active']);
        $tie = now()->subHour()->format('Y-m-d H:i:s.uP');
        $this->control->table('workspace_sign_ins')->where('org_id', $org)->update(['signed_in_at' => $tie]);
        $this->registration($relationship, $brand, $org, $partner->id, ['registered_at' => $tie]);
        $this->assertSame('lead_registered', $activity()[$relationship]['last_active']['kind']);
    }

    public function test_revocation_and_registration_serialize_on_the_partnership_and_membership(): void
    {
        [$brandUser, $brand] = $this->brand();
        [$partner, $org] = $this->creator();
        $relationship = $this->connect($brand, $org);
        $lead = $this->lead($partner, $org)['id'];

        // A revocation that locks first denies the waiting registration.
        $this->control->beginTransaction();
        $this->control->table('partnerships')->where('id', $relationship)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $waiting = $this->probe2('register', $partner, $org, $relationship, $lead);
        try {
            $this->assertBlocked($waiting);
            $this->control->rollBack();
            $this->assertSame('created', $this->probeResult($waiting)['status']);
        } finally {
            $waiting->stop(0);
        }
        $this->control->table('lead_registrations')->delete();
        $this->control->beginTransaction();
        $this->control->table('partnerships')->where('id', $relationship)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $waiting = $this->probe2('register', $partner, $org, $relationship, $lead);
        try {
            $this->assertBlocked($waiting);
            $this->control->commit();
            $this->assertSame('unavailable', $this->probeResult($waiting)['status']);
            $this->assertSame(0, $this->control->table('lead_registrations')->count() - $this->control->table('lead_registrations')->where('creator_org_id', '<>', $org)->count());
        } finally {
            $waiting->stop(0);
        }

        // A registration that locks first finishes; the waiting revocation then ends it.
        $relationship = $this->connect($brand, $org);
        $waiting = null;
        try {
            app(TenantContext::class)->run($partner->id, $org, function () use ($brandUser, $brand, $relationship, $lead, &$waiting): void {
                $this->assertSame('created', app(LeadRegistrations::class)->register($relationship, $lead, 'In flight', '', false)['status']);
                $waiting = $this->probe2('revoke', $brandUser, $brand, $relationship);
                $this->assertBlocked($waiting);
            });
            $this->assertSame('revoked', $this->probeResult($waiting)['status']);
        } finally {
            $waiting?->stop(0);
        }
        $ended = $this->control->table('lead_registrations')->where('partnership_id', $relationship)->first();
        $this->assertSame(['withdrawn', 'partnership_ended'], [$ended->state, $ended->withdrawn_reason]);
        $this->member($brandUser, $brand)->getJson('/brand')->assertJsonPath('metrics.leadsRegistered', 0);

        // A membership revocation that locks first denies the waiting registration.
        $relationship = $this->connect($brand, $org);
        $this->control->beginTransaction();
        $this->control->table('memberships')->where('org_id', $org)->where('user_id', $partner->id)->update(['revoked_at' => now()]);
        $waiting = $this->probe2('register', $partner, $org, $relationship, $lead);
        try {
            $this->assertBlocked($waiting);
            $this->control->commit();
            $this->assertSame('403', $this->probeResult($waiting)['status']);
            $this->assertSame(0, $this->control->table('lead_registrations')->where('partnership_id', $relationship)->count());
        } finally {
            $waiting->stop(0);
        }
    }

    public function test_the_shared_profile_is_optional_versioned_and_refuses_unsafe_links(): void
    {
        [$brandUser, $brand] = $this->brand();
        [$partner, $org] = $this->creator();
        $relationship = $this->connect($brand, $org);
        // Nothing is published from the account by default.
        $this->member($brandUser, $brand)->getJson('/brand/partners/'.$relationship)->assertOk()->assertJsonPath('partner.profile', null)->assertDontSee($partner->email)->assertDontSee($partner->name);
        $this->member($partner, $org)->getJson('/partner/brand-sharing')->assertOk()->assertJsonPath('profile.version', 0)->assertJsonPath('canManage', true);
        foreach (['javascript:alert(1)', 'data:text/html,x', 'ftp://example.test/file', 'https://exa mple.test', '//example.test'] as $unsafe) {
            $this->putJson('/partner-shared-profile', ['version' => 0, 'website_url' => $unsafe])->assertUnprocessable()->assertJsonValidationErrors('website_url');
        }
        $this->putJson('/partner-shared-profile', ['version' => 0, 'contact_email' => 'not-an-email'])->assertUnprocessable();
        $this->putJson('/partner-shared-profile', ['version' => 0, 'contact_name' => 'Pat Partner', 'contact_email' => 'pat@example.test', 'website_url' => 'https://example.test/pat', 'description' => 'Home and lifestyle'])->assertOk();
        $this->putJson('/partner-shared-profile', ['version' => 0, 'description' => 'Stale'])->assertConflict();
        $this->putJson('/partner-shared-profile', ['version' => 1, 'description' => 'Only a description'])->assertOk();
        $this->assertSame(2, $this->control->table('audit_logs')->where('org_id', $org)->where('event', 'shared_profile.updated')->count());
        $this->member($brandUser, $brand)->getJson('/brand/partners/'.$relationship)->assertJsonPath('partner.profile.description', 'Only a description')
            ->assertJsonPath('partner.profile.contact_name', null)->assertJsonPath('partner.profile.website_url', null);
        // The table refuses an unsafe link even from a privileged writer.
        try {
            $this->control->table('partner_shared_profiles')->where('org_id', $org)->update(['website_url' => 'javascript:alert(1)']);
            $this->fail('Unsafe link stored.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
        // A Brand without this partnership reads nothing.
        [$otherUser, $otherBrand] = $this->brand('Other');
        app(TenantContext::class)->run($otherUser->id, $otherBrand, fn () => $this->assertNull(DB::selectOne('SELECT app_partner_shared_profile(?::uuid) AS data', [$relationship])->data));
    }

    public function test_a_new_lead_can_optionally_be_registered_with_a_brand_when_it_is_captured(): void
    {
        [$brandUser, $brand] = $this->brand();
        [$otherUser, $otherBrand] = $this->brand('Other');
        [$partner, $org] = $this->creator();
        [$stranger, $strangerOrg] = $this->creator('Stranger');
        $relationship = $this->connect($brand, $org);
        $foreign = $this->connect($otherBrand, $strangerOrg);
        $lead = ['brand_name' => 'Captured for the Brand', 'contact_name' => 'Private Contact', 'contact_email' => 'private@example.test', 'notes' => 'Private capture note', 'currency' => 'PHP', 'value_minor' => '777000'];
        $leads = fn () => $this->control->table('pipeline_entries')->where('org_id', $org)->count();
        $visible = fn () => $this->member($brandUser, $brand)->getJson('/brand')->json('metrics.leadsRegistered');

        // The form is told which Brands can be chosen; the field is optional and a lead without it stays private.
        $this->member($partner, $org)->getJson('/partner/leads')->assertOk()->assertJsonPath('pipeline.registration.canRegister', true)
            ->assertJsonPath('pipeline.registration.brands.0.id', $relationship)->assertJsonCount(1, 'pipeline.registration.brands');
        $this->postJson('/pipeline', $lead)->assertCreated()->assertJsonPath('registered', false);
        $this->postJson('/pipeline', [...$lead, 'register_with' => null])->assertCreated()->assertJsonPath('registered', false);
        $this->postJson('/pipeline', [...$lead, 'register_with' => ''])->assertCreated()->assertJsonPath('registered', false);
        $this->assertSame(0, $visible());

        // Chosen: the lead and its registration are created together, with only the name and stage shared.
        $id = $this->member($partner, $org)->postJson('/pipeline', [...$lead, 'register_with' => $relationship])->assertCreated()->assertJsonPath('registered', true)->json('id');
        $registration = $this->control->table('lead_registrations')->where('pipeline_entry_id', $id)->first();
        $this->assertSame([$relationship, 'Captured for the Brand', '', false, 'lead', 'active'], [$registration->partnership_id, $registration->display_name, $registration->summary, $registration->share_contact, $registration->stage, $registration->state]);
        $this->assertSame(1, $visible());
        $this->getJson('/brand/lead-registrations/'.$registration->id)->assertOk()->assertJsonPath('registration.display_name', 'Captured for the Brand')
            ->assertJsonPath('registration.contact_email', null)->assertDontSee('Private capture note')->assertDontSee('private@example.test')->assertDontSee('777000');
        $this->getJson('/brand')->assertJsonPath('partners.0.leads_30d', 1)->assertJsonPath('partners.0.last_active.kind', 'lead_registered');
        $this->member($otherUser, $otherBrand)->getJson('/brand')->assertJsonPath('metrics.leadsRegistered', 0);

        // A refused choice saves nothing: no lead, no registration.
        $count = $leads();
        $this->member($partner, $org)->postJson('/pipeline', [...$lead, 'register_with' => $foreign])->assertUnprocessable()->assertJsonValidationErrors('register_with');
        $this->postJson('/pipeline', [...$lead, 'register_with' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('register_with');
        $this->postJson('/pipeline', [...$lead, 'register_with' => 'northline'])->assertUnprocessable();
        $this->suspend($brand, $brandUser);
        $this->postJson('/pipeline', [...$lead, 'register_with' => $relationship])->assertUnprocessable()->assertJsonValidationErrors('register_with');
        $this->control->table('platform_suspensions')->where('org_id', $brand)->update(['lifted_at' => now(), 'lifted_by' => $brandUser->id]);
        foreach (['viewer', 'member', 'manager'] as $role) {
            $this->control->table('memberships')->where('org_id', $org)->update(['role' => $role]);
            $this->postJson('/pipeline', [...$lead, 'register_with' => $relationship])->assertUnprocessable()->assertJsonValidationErrors('register_with');
            $this->getJson('/partner/leads')->assertJsonPath('pipeline.registration.canRegister', false);
        }
        $this->assertSame($count, $leads());
        $this->assertSame(1, $this->control->table('lead_registrations')->where('creator_org_id', $org)->count());
        // Those roles still capture private leads as before.
        $this->control->table('memberships')->where('org_id', $org)->update(['role' => 'member']);
        $this->postJson('/pipeline', $lead)->assertCreated()->assertJsonPath('registered', false);
        $this->assertSame(1, $visible());
    }
}
