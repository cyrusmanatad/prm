<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\PresentationDataset;
use App\Services\PresentationRegistrations;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Run once against a fresh disposable database: this deliberately exercises a real commit. */
class PresentationDatasetTest extends AcceptanceCase
{
    private function provision(string $path, bool $dry = false, int $code = 0): void
    {
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('acceptance_control');
        try {
            $this->artisan('staging:seed-presentation', ['roster' => $path, '--dry-run' => $dry])->assertExitCode($code);
        } finally {
            DB::setDefaultConnection($original);
        }
    }

    public function test_provisioning_rollback_repeat_and_real_runtime_isolation(): void
    {
        config(['staging.enabled' => true]);
        Notification::fake();
        $dataset = app(PresentationDataset::class);
        $manifest = $dataset->manifest();
        $directory = sys_get_temp_dir().'/presentation-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory.'/roster.json';
        $this->assertFalse($this->control->table('fixture_batches')->where('id', $dataset->id('batch'))->exists(), 'Use a fresh disposable database.');
        $baseline = $this->control->table('users')->count();
        try {
            config(['staging.enabled' => false]);
            $this->provision($path, code: 1);
            config(['staging.enabled' => true]);
            $this->artisan('staging:seed-presentation', ['roster' => $path, '--dry-run' => true])->assertExitCode(1); // Runtime role denied.
            $this->provision($path, true);
            $this->assertFileDoesNotExist($path);
            $this->assertSame($baseline, $this->control->table('users')->count());
            // A real foreign account must never be adopted, even with an otherwise empty dataset.
            $this->control->beginTransaction();
            $this->control->table('users')->insert(['name' => 'Collision', 'email' => $manifest['partners'][0]['email'], 'password' => 'foreign']);
            $this->provision($path, true, 1);
            $this->control->rollBack();
            // Fail after account/workspace creation, proving the entire dataset rolls back.
            $this->control->unprepared("CREATE FUNCTION presentation_test_fail() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'injected fixture failure'; END \$\$;
                CREATE TRIGGER presentation_test_fail BEFORE INSERT ON pipeline_entries FOR EACH ROW EXECUTE FUNCTION presentation_test_fail()");
            try {
                $this->provision($path, code: 1);
                $this->assertSame($baseline, $this->control->table('users')->count());
                $this->assertFalse($this->control->table('organizations')->where('id', $dataset->id('northline-living/workspace'))->exists());
                $this->assertFileDoesNotExist($path);
            } finally {
                $this->control->unprepared('DROP TRIGGER presentation_test_fail ON pipeline_entries; DROP FUNCTION presentation_test_fail()');
            }
            // A deferred COMMIT failure rolls back data but retains durable credentials for reconciliation.
            $this->control->unprepared("CREATE FUNCTION presentation_commit_fail() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'injected commit failure'; END \$\$;
                CREATE CONSTRAINT TRIGGER presentation_commit_fail AFTER INSERT ON fixture_batches DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION presentation_commit_fail()");
            try {
                $this->provision($path, code: 1);
                $this->assertSame($baseline, $this->control->table('users')->count());
                $this->assertFileExists($path);
                $this->assertCount(14, json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['accounts']);
            } finally {
                $this->control->unprepared('DROP TRIGGER presentation_commit_fail ON fixture_batches; DROP FUNCTION presentation_commit_fail()');
                if (is_file($path)) {
                    unlink($path); // Test fixture: the failed commit is known to have rolled back.
                }
            }
            $before = now()->subSecond();
            $this->provision($path);
            $rosterText = file_get_contents($path);
            $roster = json_decode($rosterText, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0600, fileperms($path) & 0777);
            $this->assertCount(14, array_unique(array_column($roster['accounts'], 'password')));
            $this->assertSame($baseline + 14, $this->control->table('users')->count());
            $this->assertSame(28, $this->control->table('fixture_marks')->where('batch_id', $dataset->id('batch'))->count());
            $orgs = array_column($roster['accounts'], 'workspace_id');
            $this->assertSame(273, $this->control->table('pipeline_entries')->whereIn('org_id', $orgs)->count());
            $this->assertSame(273, $this->control->table('pipeline_contacts')->whereIn('org_id', $orgs)->count());
            $this->assertSame(7, $this->control->table('pipeline_entries')->whereIn('org_id', $orgs)->distinct()->count('stage'));
            $this->assertGreaterThan(0, $this->control->table('reminders')->whereIn('org_id', $orgs)->whereNotNull('completed_at')->count());
            $this->assertGreaterThan(0, $this->control->table('reminders')->whereIn('org_id', $orgs)->whereNull('completed_at')->where('due_at', '<', now())->count());
            $this->assertGreaterThan(0, $this->control->table('reminders')->whereIn('org_id', $orgs)->whereNull('completed_at')->where('due_at', '>', now())->count());
            $this->assertSame(546, $this->control->table('pipeline_activities')->whereIn('org_id', $orgs)->count());
            $this->assertSame(13, $this->control->table('partner_invitations')->where('brand_org_id', $orgs[0])->where('status', 'accepted')->count());
            $this->assertSame(13, $this->control->table('partnerships')->where('brand_org_id', $orgs[0])->where('status', 'active')->count());
            $this->assertSame(14, $this->control->table('pilot_assignments')->whereIn('org_id', $orgs)->whereRaw("ends_at - starts_at = interval '90 days'")->count());
            $plan = $this->control->table('pilot_plan_versions')->where('id', $dataset->id('plan'))->first();
            $this->assertEquals(array_fill_keys(PresentationDataset::RESOURCES, 100), json_decode($plan->quotas, true));
            $approval = $this->control->table('brand_requests')->where('id', $dataset->id('approval'))->first();
            $this->assertSame('rollout', $approval->source);
            $this->assertSame('approved', $approval->status);
            $this->assertNull($approval->applicant_id);
            $this->assertGreaterThanOrEqual($before->timestamp, strtotime($approval->decided_at));
            $event = $this->control->table('brand_request_events')->where('request_id', $approval->id)->first();
            $this->assertNull($event->actor_id);
            $this->assertSame('staging:seed-presentation', $event->operator);
            $this->assertStringContainsString('not a historical administrator review', $event->reason);
            $hashes = $this->control->table('users')->whereIn('id', array_column($roster['accounts'], 'user_id'))->pluck('password', 'id')->all();
            $lead = $dataset->id($manifest['partners'][0]['key'].'/lead-01');
            $this->control->table('pipeline_entries')->where('id', $lead)->update(['notes' => 'Presentation edit preserved', 'stage' => 'active']);
            $this->provision($path);
            $this->assertSame($rosterText, file_get_contents($path));
            $this->assertSame($hashes, $this->control->table('users')->whereIn('id', array_column($roster['accounts'], 'user_id'))->pluck('password', 'id')->all());
            $this->assertSame('Presentation edit preserved', $this->control->table('pipeline_entries')->where('id', $lead)->value('notes'));
            $this->control->beginTransaction();
            $this->control->table('fixture_marks')->where('org_id', $orgs[1])->delete();
            $this->provision($path, true, 1);
            $this->control->rollBack();
            $this->control->beginTransaction();
            $this->control->table('reminders')->where('id', $dataset->id($manifest['partners'][0]['key'].'/lead-01/reminder'))->delete();
            $this->provision($path, true, 1);
            $this->control->rollBack();
            $this->withoutVite();
            foreach ($roster['accounts'] as $i => $account) {
                $user = User::findOrFail($account['user_id']);
                $this->assertNotNull($user->email_verified_at);
                $this->assertTrue(Hash::check($account['password'], $user->password));
                $this->actingAs($user)->withSession(['active_org_id' => $account['workspace_id']]);
                app(TenantContext::class)->run($user->id, $account['workspace_id'], function () use ($i, $manifest): void {
                    $expected = $i === 0 ? 0 : count($manifest['partners'][$i - 1]['leads']);
                    $this->assertSame($expected, DB::table('pipeline_entries')->count());
                    $this->assertSame($expected, DB::table('brand_contacts')->count());
                    $this->assertSame($expected, DB::table('reminders')->count());
                });
                if ($i === 0) {
                    $this->getJson('/brand')->assertOk()->assertJsonPath('metrics.activePartners', 13)->assertJsonPath('partnerPagination.total', 13)->assertJsonCount(10, 'relationships')->assertJsonPath('pipeline', null);
                } else {
                    $this->getJson('/pipeline')->assertOk();
                    // A foreign lead is inaccessible to every other Partner.
                    if ($i !== 1) {
                        $this->postJson('/pipeline/'.$lead.'/stage', ['stage' => 'lost', 'version' => 1])->assertNotFound();
                    }
                }
            }
            $this->demonstrationRegistrations($roster, $lead);
            foreach (['onboarding_requests', 'invoice_drafts', 'manual_payments', 'notification_outbox'] as $table) {
                $this->assertSame(0, $this->control->table($table)->whereIn('org_id', $orgs)->count());
            }
            $this->assertSame(0, $this->control->table('account_notification_outbox')->whereIn('user_id', array_column($roster['accounts'], 'user_id'))->count());
            Notification::assertNothingSent();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function registrations(bool $dry = false, int $code = 0): void
    {
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('acceptance_control');
        try {
            $this->artisan('staging:seed-presentation-registrations', ['--dry-run' => $dry])->assertExitCode($code);
        } finally {
            DB::setDefaultConnection($original);
        }
    }

    /** The separately versioned demonstration registrations (D38): 13 listed leads, once, with the other 260 left private. */
    private function demonstrationRegistrations(array $roster, string $firstLead): void
    {
        $manifest = app(PresentationRegistrations::class)->manifest();
        $ids = array_column($manifest['registrations'], 'registration_id');
        $count = fn () => $this->control->table('lead_registrations')->whereIn('partnership_id', array_column($manifest['registrations'], 'partnership_id'))->count();
        $brand = User::findOrFail($roster['accounts'][0]['user_id']);
        $hub = fn () => $this->actingAs($brand)->withSession(['active_org_id' => $roster['accounts'][0]['workspace_id']])->getJson('/brand')->assertOk();
        $hub()->assertJsonPath('metrics.leadsRegistered', 0);
        // Refused outside staging mode and for the runtime role; a dry run writes nothing.
        config(['staging.enabled' => false]);
        $this->registrations(code: 1);
        config(['staging.enabled' => true]);
        $this->artisan('staging:seed-presentation-registrations')->assertExitCode(1);
        $this->registrations(true);
        $this->assertSame(0, $count());
        $this->assertFalse($this->control->table('fixture_batches')->where('id', $manifest['batch_id'])->exists());
        // A listed lead that is no longer the listed company, an ended partnership, or a registration a person already made, stops everything.
        foreach ([
            fn () => $this->control->table('pipeline_entries')->where('id', $manifest['registrations'][3]['pipeline_entry_id'])->update(['brand_name' => 'Renamed by its Partner']),
            fn () => $this->control->table('partnerships')->where('id', $manifest['registrations'][5]['partnership_id'])->update(['status' => 'revoked', 'revoked_at' => now()]),
            fn () => $this->control->table('lead_registrations')->insert(['id' => (string) Str::uuid(), 'partnership_id' => $manifest['registrations'][0]['partnership_id'],
                'brand_org_id' => $manifest['brand']['org_id'], 'creator_org_id' => $manifest['registrations'][0]['creator_org_id'], 'pipeline_entry_id' => $manifest['registrations'][0]['pipeline_entry_id'],
                'display_name' => 'Registered by a person', 'stage' => 'lead', 'registered_by' => $roster['accounts'][1]['user_id'], 'registered_at' => now(), 'shared_updated_at' => now()]),
        ] as $conflict) {
            $this->control->beginTransaction();
            $conflict();
            $this->registrations(code: 1);
            $this->registrations(true, 1);
            $this->control->rollBack();
            $this->assertSame(0, $count());
        }
        $this->registrationConcurrency($manifest, $roster);
        $before = now()->subSecond();
        $this->registrations();
        $this->assertSame(13, $count());
        $this->assertSame(13, $this->control->table('lead_registrations')->whereIn('id', $ids)->where('state', 'active')->where('share_contact', false)->where('registered_at', '>=', $before)->count());
        $this->assertSame(13, $this->control->table('audit_logs')->where('event', 'staging.presentation_registration')->whereIn('subject_id', $ids)->count());
        // The stage is the lead's current stage, including an edit made after the dataset was seeded.
        $this->assertSame('active', $this->control->table('lead_registrations')->where('pipeline_entry_id', $firstLead)->value('stage'));
        // Repeats change nothing; a partly removed set is refused rather than refilled.
        $state = $this->control->table('lead_registrations')->orderBy('id')->get()->all();
        $this->registrations();
        $this->registrations(true);
        $this->assertEquals($state, $this->control->table('lead_registrations')->orderBy('id')->get()->all());
        $this->control->beginTransaction();
        $this->control->table('lead_registrations')->where('id', $ids[7])->delete();
        $this->registrations(code: 1);
        $this->control->rollBack();
        // The Brand sees exactly the 13 listed leads; the other 260 and every private field stay private.
        $hub()->assertJsonPath('metrics.leadsRegistered', 13)->assertJsonPath('partners.0.leads_30d', 1)->assertJsonPath('partners.0.last_active.kind', 'lead_registered');
        $list = $this->getJson('/brand/lead-registrations')->assertOk()->assertJsonPath('pagination.total', 13)->assertJsonCount(13, 'registrations');
        foreach (['Alder Table Studio', 'avery.lane@example.test', 'Avery Lane', 'usage-rights discussion', 'value_minor'] as $private) {
            $list->assertDontSee($private);
        }
        $this->getJson('/brand/lead-registrations/'.$ids[0])->assertOk()->assertJsonPath('registration.display_name', $manifest['registrations'][0]['display_name'])
            ->assertJsonPath('registration.contact_email', null)->assertDontSee('usage-rights discussion')->assertDontSee('avery.lane@example.test');
        $this->assertSame(260, $this->control->table('pipeline_entries')->whereIn('org_id', array_column($manifest['registrations'], 'creator_org_id'))->whereNotIn('id', array_column($manifest['registrations'], 'pipeline_entry_id'))->count());
        // The base dataset still verifies after registrations exist.
        $this->assertSame(28, $this->control->table('fixture_marks')->where('batch_id', $manifest['dataset']['batch_id'])->count());
    }

    private function registrationConcurrency(array $manifest, array $roster): void
    {
        $relationship = $manifest['registrations'][0]['partnership_id'];
        // Revocation first: the seeder must actually wait, then see the committed revoked row.
        $this->control->beginTransaction();
        $this->control->table('partnerships')->where('id', $relationship)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $seeder = new Process([PHP_BINARY, 'tests/Fixtures/presentation-registration-probe.php'], base_path(), [
            'APP_ENV' => 'testing', 'DB_USERNAME' => 'weavdash_migrator',
            'DB_PASSWORD' => getenv('TEST_MIGRATION_DB_PASSWORD'), 'TEST_MIGRATION_DB_PASSWORD' => false,
        ]);
        $seeder->setTimeout(15);
        $seeder->start();
        try {
            $this->eventually(fn () => str_contains($seeder->getOutput(), "\n"), 'Seeder probe did not connect.');
            $this->assertBlocked($seeder);
            $this->control->commit();
            $this->assertSame('refused', $this->probeResult($seeder)['status']);
            $this->assertSame(0, $this->control->table('lead_registrations')->whereIn('partnership_id', array_column($manifest['registrations'], 'partnership_id'))->count());
        } finally {
            $seeder->stop(0);
        }
        $this->control->table('partnerships')->where('id', $relationship)->update(['status' => 'active', 'revoked_at' => null]);

        // Seeder first: product revocation waits, then withdraws the committed registration.
        $this->control->beginTransaction();
        $this->registrations();
        $revoker = new Process([PHP_BINARY, 'tests/Fixtures/lead-registration-probe.php', 'revoke',
            (string) $roster['accounts'][0]['user_id'], $manifest['brand']['org_id'], $relationship], base_path(), [
                'APP_ENV' => 'testing', 'TEST_MIGRATION_DB_PASSWORD' => false,
            ]);
        $revoker->setTimeout(15);
        $revoker->start();
        try {
            $this->eventually(fn () => str_contains($revoker->getOutput(), "\n"), 'Revocation probe did not connect.');
            $this->assertBlocked($revoker);
            $this->control->commit();
            $this->assertSame('revoked', $this->probeResult($revoker)['status']);
            $this->assertSame('withdrawn', $this->control->table('lead_registrations')->where('partnership_id', $relationship)->value('state'));
            $this->assertSame(0, $this->control->table('lead_registrations as r')->join('partnerships as p', 'p.id', '=', 'r.partnership_id')
                ->whereIn('r.id', array_column($manifest['registrations'], 'registration_id'))->where('r.state', 'active')->where('p.status', 'revoked')->count());
        } finally {
            $revoker->stop(0);
        }
        // Restore only these disposable test fixtures for the remaining creation/idempotency assertions.
        $ids = array_column($manifest['registrations'], 'registration_id');
        $this->control->table('lead_registrations')->whereIn('id', $ids)->delete();
        $this->control->table('audit_logs')->where('event', 'staging.presentation_registration')->whereIn('subject_id', $ids)->delete();
        $this->control->table('fixture_batches')->where('id', $manifest['batch_id'])->delete();
        $this->control->table('partnerships')->where('id', $relationship)->update(['status' => 'active', 'revoked_at' => null]);
    }
}
