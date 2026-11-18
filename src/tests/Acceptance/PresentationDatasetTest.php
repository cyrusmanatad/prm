<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\PresentationDataset;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

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
}
