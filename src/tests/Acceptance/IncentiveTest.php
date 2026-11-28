<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\PrivacyExportData;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BrandFixture;

/** Source campaigns on registrations (D43) and Brand incentives (D44) against the runtime role and row security. */
class IncentiveTest extends AcceptanceCase
{
    private function brand(): array
    {
        $user = User::factory()->create();

        return [$user, BrandFixture::create($user->id, 'Brand '.Str::random(6), $this->control)];
    }

    private function creator(): array
    {
        $user = User::factory()->create();

        return [$user, app(Workspaces::class)->create($user->id, 'Partner '.Str::random(6))];
    }

    private function connect(string $brandOrg, string $creatorOrg): string
    {
        $invitation = (string) Str::uuid();
        $id = (string) Str::uuid();
        $this->control->table('partner_invitations')->insert(['id' => $invitation, 'brand_org_id' => $brandOrg, 'recipient_email' => Str::random(12).'@example.test',
            'token_hash' => hash('sha256', $invitation), 'status' => 'accepted', 'expires_at' => now()->addDay()]);
        $this->control->table('partnerships')->insert(['id' => $id, 'brand_org_id' => $brandOrg, 'creator_org_id' => $creatorOrg, 'brand_name' => 'Brand',
            'creator_name' => 'Partner '.Str::random(6), 'invitation_id' => $invitation, 'accepted_at' => now()->subDay()]);

        return $id;
    }

    private function as(User $user, string $org): static
    {
        return $this->actingAs($user)->withSession(['active_org_id' => $org]);
    }

    /** A shared brief from the Brand to one partnership. */
    private function brief(User $brand, string $org, string $partnership, string $title = 'Winter launch'): string
    {
        $id = $this->as($brand, $org)->postJson('/brand/campaigns', ['title' => $title])->assertCreated()->json('id');
        $this->patchJson("/brand/campaigns/$id/share", ['version' => 1, 'brief' => 'Bring onboarded leads', 'due_date' => '2027-01-15', 'partner' => $partnership])->assertOk();

        return $id;
    }

    /** A private lead at a stage, registered with the partnership and optionally a source campaign. */
    private function registered(User $partner, string $org, string $partnership, string $stage = 'onboarded', ?string $campaign = null): string
    {
        $entry = $this->as($partner, $org)->postJson('/pipeline', ['brand_name' => 'Lead '.Str::random(5), 'currency' => 'PHP', 'value_minor' => '100'])->assertCreated()->json('id');
        $this->control->table('pipeline_entries')->where('id', $entry)->update(['stage' => $stage]);

        return $this->postJson('/lead-registrations', ['partnership' => $partnership, 'entry' => $entry, 'display_name' => 'Lead '.Str::random(4), 'campaign' => $campaign])
            ->assertCreated()->json('id');
    }

    private function offer(User $brand, string $org, array $data, bool $share = true): array
    {
        $id = $this->as($brand, $org)->postJson('/brand/incentives', ['title' => $data['title'] ?? 'Onboarding bonus', 'campaign_id' => $data['campaign_id'] ?? null])
            ->assertCreated()->json('id');
        $fields = ['version' => 1, 'title' => 'Onboarding bonus', 'terms' => 'Paid by bank transfer within 30 days.', 'reward_type' => 'cash',
            'amount_minor' => 200000, 'currency' => 'PHP', 'rule_stage' => 'onboarded', 'leads_per_award' => 1,
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(), ...$data];
        if ($share) {
            $this->patchJson("/brand/incentives/$id/share", $fields)->assertOk();
        }

        return [$id, $fields];
    }

    private function award(string $incentive, string $creatorOrg): object
    {
        return $this->control->table('incentive_awards')->where('incentive_id', $incentive)->where('creator_org_id', $creatorOrg)->latest('claimed_at')->first();
    }

    public function test_partner_links_a_registration_to_a_shared_brief_and_the_brand_sees_it(): void
    {
        [$brand, $bo] = $this->brand();
        [$partner, $po] = $this->creator();
        [$otherPartner, $oo] = $this->creator();
        $relationship = $this->connect($bo, $po);
        $otherRelationship = $this->connect($bo, $oo);
        $brief = $this->brief($brand, $bo, $relationship);
        $otherBrief = $this->brief($brand, $bo, $otherRelationship, 'Other brief');
        $draft = $this->as($brand, $bo)->postJson('/brand/campaigns', ['title' => 'Draft'])->json('id');

        // Only briefs shared on the registration's own partnership can be its source.
        $entry = $this->as($partner, $po)->postJson('/pipeline', ['brand_name' => 'Acme', 'currency' => 'PHP', 'value_minor' => '1'])->json('id');
        foreach ([$draft, $otherBrief, (string) Str::uuid()] as $wrong) {
            $this->postJson('/lead-registrations', ['partnership' => $relationship, 'entry' => $entry, 'display_name' => 'Acme', 'campaign' => $wrong])
                ->assertJsonValidationErrors('campaign');
        }
        $this->assertSame(0, $this->control->table('lead_registrations')->where('pipeline_entry_id', $entry)->count());
        $registration = $this->postJson('/lead-registrations', ['partnership' => $relationship, 'entry' => $entry, 'display_name' => 'Acme', 'campaign' => $brief])
            ->assertCreated()->json('id');
        $row = $this->control->table('lead_registrations')->where('id', $registration)->first();
        $this->assertSame($brief, $row->campaign_id);
        $this->assertNotNull($row->campaign_linked_at);
        $this->assertSame(1, $this->control->table('audit_logs')->where('subject_id', $registration)->where('event', 'lead_registration.campaign_set')->count());

        // The Partner sees its link on the brief and read-only on its private lead.
        $this->getJson("/partner/campaigns/$brief")->assertJsonPath('leads.0.id', $registration);
        $this->getJson("/partner/leads?entry=$entry")->assertJsonPath('pipeline.selected.sharing.0.campaign.id', $brief);
        $this->getJson('/partner/brand-sharing')->assertJsonPath("campaigns.$relationship.0.id", $brief)
            ->assertJsonMissingPath("campaigns.$otherRelationship");

        // The Brand sees it on the campaign, in the drawer and through the campaign filter.
        $this->as($brand, $bo)->getJson("/brand/campaigns/$brief")->assertJsonPath('leads.0.id', $registration)->assertJsonPath('selected.leads', 1);
        $this->getJson("/brand/lead-registrations?registration=$registration")->assertJsonPath('selected.campaign.id', $brief);
        $this->getJson("/brand/lead-registrations?campaign=$brief")->assertJsonCount(1, 'registrations');
        $this->getJson("/brand/lead-registrations?campaign=$otherBrief")->assertJsonCount(0, 'registrations');
        $this->getJson('/brand/campaigns')->assertJsonPath('counts.leads', 1);
        // Another Partner of the same Brand sees nothing of it.
        $this->as($otherPartner, $oo)->getJson("/partner/campaigns/$otherBrief")->assertJsonCount(0, 'leads');

        // Editing without the field keeps the link; an empty value clears it and its time.
        $this->as($partner, $po)->patchJson("/lead-registrations/$registration", ['version' => 1, 'display_name' => 'Acme renamed'])->assertOk();
        $this->assertSame($brief, $this->control->table('lead_registrations')->where('id', $registration)->value('campaign_id'));
        $this->patchJson("/lead-registrations/$registration", ['version' => 1, 'display_name' => 'Acme', 'campaign' => ''])->assertStatus(409);
        $this->patchJson("/lead-registrations/$registration", ['version' => 2, 'display_name' => 'Acme', 'campaign' => ''])->assertOk();
        $row = $this->control->table('lead_registrations')->where('id', $registration)->first();
        $this->assertNull($row->campaign_id);
        $this->assertNull($row->campaign_linked_at);
        $this->assertSame(1, $this->control->table('audit_logs')->where('subject_id', $registration)->where('event', 'lead_registration.campaign_cleared')->count());

        // Withdrawal keeps the link as Partner history and hides it from the Brand.
        $this->patchJson("/lead-registrations/$registration", ['version' => 3, 'display_name' => 'Acme', 'campaign' => $brief])->assertOk();
        $this->deleteJson("/lead-registrations/$registration", ['version' => 4])->assertOk();
        $this->assertSame($brief, $this->control->table('lead_registrations')->where('id', $registration)->value('campaign_id'));
        $this->as($brand, $bo)->getJson("/brand/campaigns/$brief")->assertJsonCount(0, 'leads');

        // The database refuses a link across partnerships even from the migration role.
        try {
            $this->control->table('lead_registrations')->where('id', $registration)->update(['campaign_id' => $otherBrief]);
            $this->fail('Cross-partnership campaign link accepted');
        } catch (QueryException $error) {
            $this->assertSame('23503', $error->errorInfo[0]);
        }
    }

    public function test_offers_are_private_drafts_until_shared_and_only_the_audience_sees_them(): void
    {
        [$brand, $bo] = $this->brand();
        [$otherBrand, $ob] = $this->brand();
        [$partner, $po] = $this->creator();
        [$outsider, $oo] = $this->creator();
        $relationship = $this->connect($bo, $po);
        $this->connect($bo, $oo);
        [$id, $fields] = $this->offer($brand, $bo, [], share: false);
        $path = "/brand/incentives/$id";

        $this->as($brand, $bo)->getJson($path)->assertJsonPath('selected.state', 'draft')->assertJsonCount(2, 'partners');
        $this->as($partner, $po)->getJson('/partner/incentives')->assertJsonCount(0, 'offers');
        $this->as($otherBrand, $ob)->getJson($path)->assertNotFound();
        $this->patchJson("$path/draft", $fields)->assertNotFound();

        // Sharing checks every field and the audience; a draft save checks only the title.
        $this->as($brand, $bo)->patchJson("$path/draft", ['version' => 1, 'title' => 'Draft only', 'reward_type' => 'cash', 'rule_stage' => 'onboarded', 'leads_per_award' => 1])->assertOk();
        $this->patchJson("$path/share", ['version' => 2, 'title' => 'x', 'reward_type' => 'cash', 'rule_stage' => 'onboarded', 'leads_per_award' => 1])
            ->assertJsonValidationErrors(['terms', 'amount_minor', 'currency', 'starts_on', 'ends_on']);
        $this->patchJson("$path/share", [...$fields, 'version' => 2, 'audience' => []])->assertJsonValidationErrors('audience');
        $this->patchJson("$path/share", [...$fields, 'version' => 2, 'audience' => [(string) Str::uuid()]])->assertJsonValidationErrors('audience');
        $this->patchJson("$path/share", [...$fields, 'version' => 1, 'audience' => [$relationship]])->assertStatus(409);
        $this->patchJson("$path/share", [...$fields, 'version' => 2, 'audience' => [$relationship], 'cap_per_partner' => 5, 'cap_total' => 2])
            ->assertJsonValidationErrors('cap_per_partner');
        $this->patchJson("$path/share", [...$fields, 'version' => 2, 'audience' => [$relationship], 'cap_per_partner' => 2, 'cap_total' => 3])->assertOk();
        $this->getJson($path)->assertJsonPath('selected.state', 'shared')->assertJsonPath('selected.audience.0.id', $relationship);
        // Shared terms are read-only.
        $this->patchJson("$path/draft", [...$fields, 'version' => 3])->assertStatus(409);
        $this->assertSame('{}', $this->control->table('incentives')->where('id', $id)->value('audience'));

        // The audience Partner sees the offer and its own cap, never the total cap; another Partner sees nothing.
        $offer = $this->as($partner, $po)->getJson('/partner/incentives')->assertJsonCount(1, 'offers')->json('offers.0');
        $this->assertSame(2, $offer['cap_per_partner']);
        $this->assertArrayNotHasKey('cap_total', $offer);
        $this->assertTrue($offer['has_room']);
        $this->as($outsider, $oo)->getJson('/partner/incentives')->assertJsonCount(0, 'offers');
        app(TenantContext::class)->run($outsider->id, $oo, function () use ($id): void {
            $this->assertSame(0, DB::table('incentives')->where('id', $id)->count());
            $this->assertSame(0, DB::table('incentive_partners')->count());
        });

        // The runtime role cannot write the tables directly.
        app(TenantContext::class)->run($brand->id, $bo, function () use ($id): void {
            $this->assertSame(1, DB::table('incentives')->where('id', $id)->count());
            try {
                DB::transaction(fn () => DB::table('incentives')->where('id', $id)->update(['title' => 'Forged']));
                $this->fail('Direct incentive update accepted');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
            }
        });

        // Ending stops new claims and is audited.
        $this->as($brand, $bo)->postJson("$path/end", ['version' => 3])->assertOk();
        $this->postJson("$path/end", ['version' => 4])->assertStatus(409);
        $this->assertSame(1, $this->control->table('audit_logs')->where('subject_id', $id)->where('event', 'incentive.ended')->count());
        $this->as($partner, $po)->getJson('/partner/incentives')->assertJsonPath('offers.0.state', 'ended')->assertJsonPath('offers.0.open', false);
    }

    public function test_claims_decisions_caps_fulfilment_and_reversal(): void
    {
        [$brand, $bo] = $this->brand();
        [$partner, $po] = $this->creator();
        $relationship = $this->connect($bo, $po);
        [$id, $fields] = $this->offer($brand, $bo, ['audience' => [$relationship], 'cap_per_partner' => 2]);
        $ready = $this->registered($partner, $po, $relationship);
        $second = $this->registered($partner, $po, $relationship, 'active');
        $early = $this->registered($partner, $po, $relationship, 'pitched');
        $claims = "/partner/incentives/$id/claims";

        $candidates = collect($this->as($partner, $po)->getJson('/partner/incentives')->json('offers.0.candidates'))->keyBy('id');
        $this->assertTrue($candidates[$ready]['eligible']);
        $this->assertSame('stage', $candidates[$early]['reason']);
        $this->postJson($claims, ['partnership' => $relationship, 'registrations' => [$early]])->assertJsonValidationErrors('registrations');
        $this->postJson($claims, ['partnership' => $relationship, 'registrations' => [$ready, $second]])->assertJsonValidationErrors('registrations');
        $this->postJson($claims, ['partnership' => $relationship, 'registrations' => [$ready], 'note' => 'Signed last week'])->assertCreated();
        // A registration supports one open or granted award per incentive.
        $this->postJson($claims, ['partnership' => $relationship, 'registrations' => [$ready]])->assertJsonValidationErrors('registrations');
        $award = $this->award($id, $po);
        $this->assertSame('pending', $award->state);
        $this->assertSame('Signed last week', $award->note);

        // The Brand decides; a changed amount and a rejection need a reason; stale versions are refused.
        $decide = "/brand/incentive-awards/$award->id";
        $this->as($brand, $bo)->patchJson("$decide/approve", ['version' => 1, 'amount_minor' => 150000])->assertJsonValidationErrors('reason');
        $this->patchJson("$decide/reject", ['version' => 1])->assertJsonValidationErrors('reason');
        $this->patchJson("$decide/approve", ['version' => 2])->assertStatus(409);
        $this->patchJson("$decide/approve", ['version' => 1, 'amount_minor' => 150000, 'reason' => 'Partial month'])->assertOk();
        $award = $this->award($id, $po);
        $this->assertSame(['approved', 150000, 'Partial month'], [$award->state, (int) $award->amount_minor, $award->amount_reason]);
        $this->getJson("/brand/incentives?award=$award->id")->assertJsonPath('award.granted_partner', 1)->assertJsonPath('award.leads.0.meets', true);

        // Fulfilment needs a past or current date; reversal returns the award to review with a reason.
        $this->patchJson("$decide/fulfil", ['version' => 2, 'fulfilled_on' => now()->addDays(2)->toDateString()])->assertJsonValidationErrors('fulfilled_on');
        $this->patchJson("$decide/reverse", ['version' => 2])->assertJsonValidationErrors('reason');
        $this->patchJson("$decide/reverse", ['version' => 2, 'reason' => 'Lead cancelled'])->assertOk();
        $this->assertSame('pending', $this->award($id, $po)->state);
        $this->patchJson("$decide/approve", ['version' => 3])->assertOk();
        $this->patchJson("$decide/fulfil", ['version' => 4, 'fulfilled_on' => now()->toDateString(), 'reference' => 'BDO 1234'])->assertOk();
        $this->as($partner, $po)->getJson('/partner/incentives')->assertJsonPath('awards.0.state', 'fulfilled')
            ->assertJsonPath('awards.0.fulfilment_reference', 'BDO 1234')->assertJsonPath('figures.received', 1);
        // My Leads "Rewards earned" sums delivered cash per currency (re-approval restored the offer amount).
        $rewards = $this->getJson('/partner')->assertOk()->json('pipeline.rewards');
        $this->assertSame(['earned' => [['currency' => $fields['currency'], 'minor' => '200000']], 'nonCash' => 0, 'awaiting' => 0], $rewards);

        // The Partner's own cap counts open and granted claims; a rejected claim frees its lead.
        $this->postJson($claims, ['partnership' => $relationship, 'registrations' => [$second]])->assertCreated();
        $third = $this->registered($partner, $po, $relationship);
        $this->postJson($claims, ['partnership' => $relationship, 'registrations' => [$third]])->assertJsonValidationErrors('registrations');
        $pending = $this->award($id, $po);
        $this->as($brand, $bo)->patchJson("/brand/incentive-awards/$pending->id/reject", ['version' => 1, 'reason' => 'Duplicate of an earlier lead'])->assertOk();
        $this->as($partner, $po)->postJson($claims, ['partnership' => $relationship, 'registrations' => [$second]])->assertCreated();

        foreach (['incentive_award.claimed', 'incentive_award.approved', 'incentive_award.rejected', 'incentive_award.reversed', 'incentive_award.fulfilled'] as $event) {
            $this->assertTrue($this->control->table('audit_logs')->where('event', $event)->exists(), $event);
        }
    }

    public function test_withdrawal_and_relationship_end_cancel_pending_claims_and_keep_granted_awards(): void
    {
        [$brand, $bo] = $this->brand();
        [$partner, $po] = $this->creator();
        $relationship = $this->connect($bo, $po);
        [$id] = $this->offer($brand, $bo, ['audience' => [$relationship]]);
        $first = $this->registered($partner, $po, $relationship);
        $second = $this->registered($partner, $po, $relationship);
        $this->as($partner, $po)->postJson("/partner/incentives/$id/claims", ['partnership' => $relationship, 'registrations' => [$first]])->assertCreated();
        $granted = $this->award($id, $po);
        $this->as($brand, $bo)->patchJson("/brand/incentive-awards/$granted->id/approve", ['version' => 1])->assertOk();
        $this->as($partner, $po)->postJson("/partner/incentives/$id/claims", ['partnership' => $relationship, 'registrations' => [$second]])->assertCreated();
        $pending = $this->award($id, $po);

        $this->deleteJson("/lead-registrations/$second", ['version' => 1])->assertOk();
        $this->assertSame(['cancelled', 'withdrawn'], [$this->control->table('incentive_awards')->where('id', $pending->id)->value('state'),
            $this->control->table('incentive_awards')->where('id', $pending->id)->value('cancel_reason')]);
        $this->assertTrue((bool) $this->control->table('incentive_award_registrations')->where('award_id', $pending->id)->value('released'));

        $this->as($brand, $bo)->deleteJson("/partnerships/$relationship")->assertOk();
        $this->assertSame('approved', $this->control->table('incentive_awards')->where('id', $granted->id)->value('state'));
        // The Brand keeps its record; the Partner no longer sees the offer or its awards.
        $this->getJson("/brand/incentives/$id")->assertJsonCount(2, 'selected.awards');
        $this->as($partner, $po)->getJson('/partner/incentives')->assertJsonCount(0, 'offers')->assertJsonCount(0, 'awards');
        $export = app(TenantContext::class)->run($brand->id, $bo, fn () => app(PrivacyExportData::class)->collect($brand->id, $bo));
        foreach (['incentives', 'incentive_partners', 'incentive_awards', 'incentive_award_registrations'] as $table) {
            $this->assertNotEmpty($export[$table] ?? [], $table);
        }
        $inventory = json_decode($this->control->selectOne("SELECT app_privacy_inventory('workspace', NULL, ?::uuid) AS data", [$bo])->data, true);
        $this->assertCount(2, $inventory['incentive_awards']);
    }

    public function test_campaign_incentive_confirms_and_locks_the_source_campaign(): void
    {
        [$brand, $bo] = $this->brand();
        [$partner, $po] = $this->creator();
        $relationship = $this->connect($bo, $po);
        $brief = $this->brief($brand, $bo, $relationship);
        [$id] = $this->offer($brand, $bo, ['campaign_id' => $brief, 'audience' => []]);
        $this->getJson("/brand/incentives/$id")->assertJsonPath('selected.audience.0.id', $relationship);
        $plain = $this->registered($partner, $po, $relationship);
        $linked = $this->registered($partner, $po, $relationship, 'onboarded', $brief);
        $this->as($partner, $po)->postJson("/partner/incentives/$id/claims", ['partnership' => $relationship, 'registrations' => [$plain]])->assertJsonValidationErrors('registrations');
        $this->postJson("/partner/incentives/$id/claims", ['partnership' => $relationship, 'registrations' => [$linked]])->assertCreated();
        // An open award locks the link it relies on.
        $this->patchJson("/lead-registrations/$linked", ['version' => 1, 'display_name' => 'Renamed', 'campaign' => ''])->assertJsonValidationErrors('campaign');
        $this->assertNull($this->control->table('lead_registrations')->where('id', $linked)->value('campaign_confirmed_at'));
        $award = $this->award($id, $po);
        $this->as($brand, $bo)->patchJson("/brand/incentive-awards/$award->id/approve", ['version' => 1])->assertOk();
        $this->assertNotNull($this->control->table('lead_registrations')->where('id', $linked)->value('campaign_confirmed_at'));
        $this->getJson("/brand/campaigns/$brief")->assertJsonPath('leads.0.confirmed', true)->assertJsonPath('incentive.id', $id);
    }

    public function test_concurrent_approvals_cannot_exceed_the_total_cap(): void
    {
        [$brand, $bo] = $this->brand();
        [$first, $fo] = $this->creator();
        [$second, $so] = $this->creator();
        $a = $this->connect($bo, $fo);
        $b = $this->connect($bo, $so);
        [$id] = $this->offer($brand, $bo, ['audience' => [$a, $b], 'cap_total' => 1]);
        foreach ([[$first, $fo, $a], [$second, $so, $b]] as [$user, $org, $relationship]) {
            $lead = $this->registered($user, $org, $relationship);
            $this->as($user, $org)->postJson("/partner/incentives/$id/claims", ['partnership' => $relationship, 'registrations' => [$lead]])->assertCreated();
        }
        $awards = [$this->award($id, $fo)->id, $this->award($id, $so)->id];

        // Hold the incentive lock so both approvals queue behind it, then release it.
        $this->control->beginTransaction();
        $this->control->select('SELECT id FROM incentives WHERE id = ? FOR UPDATE', [$id]);
        $probes = array_map(fn ($award) => $this->approval($brand->id, $bo, $award), $awards);
        foreach ($probes as $probe) {
            $this->assertBlocked($probe);
        }
        $this->control->rollBack();
        $results = array_map(fn ($probe) => $this->probeResult($probe)['result'], $probes);
        sort($results);
        $this->assertSame(['decision', 'ok'], $results);
        $this->assertSame(1, $this->control->table('incentive_awards')->where('incentive_id', $id)->where('state', 'approved')->count());
    }

    private function approval(int $actor, string $org, string $award): Process
    {
        $process = new Process([PHP_BINARY, 'tests/Fixtures/incentive-approval.php', (string) $actor, $org, $award, '1'], base_path(),
            ['APP_ENV' => 'testing', 'TEST_MIGRATION_DB_PASSWORD' => false]);
        $process->setTimeout(15);
        $process->start();
        $this->eventually(fn () => str_contains($process->getOutput(), "\n"), 'Probe failed to connect: '.$process->getErrorOutput());

        return $process;
    }
}
