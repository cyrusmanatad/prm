<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\UploadReconciliation;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToListContents;
use Mockery;

/**
 * PRIV-05 (P4, D40). The acceptance database is shared, so every assertion reads this test's own keys.
 */
class UploadReconciliationTest extends AcceptanceCase
{
    private const PASSWORD = 'SyntheticReconcilePassword123!';

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('onboarding');
    }

    /** A workspace with one onboarding request in the given status. */
    private function request(string $status = 'draft'): array
    {
        $owner = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $org = app(Workspaces::class)->create($owner->id, 'Reconcile '.Str::uuid());
        $lead = (string) Str::uuid();
        $this->control->table('pipeline_entries')->insert(['id' => $lead, 'org_id' => $org, 'brand_name' => 'Synthetic lead', 'currency' => 'USD']);
        $request = (string) Str::uuid();
        $this->control->table('onboarding_requests')->insert(['id' => $request, 'org_id' => $org, 'pipeline_entry_id' => $lead, 'label' => 'Documents',
            'token_hash' => hash('sha256', $request), 'expires_at' => now()->addDay(), 'status' => $status]);

        return [$org, $request];
    }

    /** Stores a file and, unless $record is false, its document record. */
    private function upload(string $org, string $request, string $state = 'clean', bool $record = true, bool $file = true): string
    {
        $id = (string) Str::uuid();
        $key = 'onboarding/'.$org.'/'.$request.'/'.$id;
        if ($file) {
            Storage::disk('onboarding')->put($key, 'Synthetic document');
        }
        if ($record) {
            $this->control->table('onboarding_documents')->insert(['id' => $id, 'org_id' => $org, 'request_id' => $request, 'name' => 'file.txt', 'object_key' => $key,
                'checksum' => hash('sha256', 'Synthetic document'), 'size' => 18, 'mime' => 'text/plain', 'scan_state' => $state]);
        }

        return $key;
    }

    private function finding(string $key): ?object
    {
        return $this->control->table('upload_reconciliation_findings')->where('object_key', $key)->first();
    }

    /** Runs as the runtime role, with files treated as older than the in-progress grace. */
    private function reconcile(int $ahead = UploadReconciliation::GRACE_SECONDS + 60): array
    {
        return app(UploadReconciliation::class)->run(time() + $ahead);
    }

    public function test_classifies_orphans_missing_files_held_uploads_and_unrecognised_keys_without_deleting_anything(): void
    {
        [$org, $request] = $this->request();
        [$submittedOrg, $submitted] = $this->request('submitted');
        $clean = $this->upload($org, $request);
        $orphan = $this->upload($org, $request, record: false);
        $missing = $this->upload($org, $request, file: false);
        $pending = $this->upload($org, $request, 'pending');
        $rejected = $this->upload($org, $request, 'rejected');
        $unscanned = $this->upload($org, $request, 'unscanned');
        $closedPending = $this->upload($submittedOrg, $submitted, 'pending');
        $legacyId = (string) Str::uuid();
        $legacy = 'legacy-store/'.$legacyId;
        $this->control->table('onboarding_documents')->insert(['id' => $legacyId, 'org_id' => $org, 'request_id' => $request, 'name' => 'old.txt', 'object_key' => $legacy,
            'checksum' => hash('sha256', 'x'), 'size' => 1, 'mime' => 'text/plain', 'scan_state' => 'clean']);
        $odd = 'onboarding/not-a-managed-key-'.Str::random(6).'.txt';
        Storage::disk('onboarding')->put($odd, 'Unknown');

        $result = $this->reconcile();

        $this->assertSame('completed', $result['status']);
        $this->assertNull($this->finding($clean));
        $this->assertSame('orphaned', $this->finding($orphan)->kind);
        $this->assertSame([$org, $request], [$this->finding($orphan)->org_id, $this->finding($orphan)->request_id]);
        $this->assertSame('missing', $this->finding($missing)->kind);
        $this->assertSame(['missing', $org, $request, $legacyId], [$this->finding($legacy)->kind, $this->finding($legacy)->org_id, $this->finding($legacy)->request_id, $this->finding($legacy)->document_id],
            'A record is attributed from its own identifiers, whatever its key looks like.');
        $this->assertNull($this->finding($missing)->size);
        $this->assertSame(['held', 'held'], [$this->finding($pending)->kind, $this->finding($rejected)->kind]);
        // Staging's unscanned state is not a scanning failure, and a submitted request is no longer editable.
        $this->assertNull($this->finding($unscanned));
        $this->assertNull($this->finding($closedPending));
        $this->assertSame('unrecognised', $this->finding($odd)->kind);
        $this->assertNull($this->finding($odd)->org_id);
        // Report only: every stored file is still there.
        foreach ([$clean, $orphan, $pending, $rejected, $unscanned, $closedPending, $odd] as $key) {
            Storage::disk('onboarding')->assertExists($key);
        }
    }

    public function test_recent_uploads_are_not_judged_while_their_record_may_still_be_committing(): void
    {
        [$org, $request] = $this->request();
        $orphan = $this->upload($org, $request, record: false);

        $result = $this->reconcile(0);

        $this->assertNull($this->finding($orphan));
        $this->assertGreaterThanOrEqual(1, $result['skipped']);
        // Once it is older than the grace, it is reported.
        $this->assertSame('completed', $this->reconcile()['status']);
        $this->assertSame('orphaned', $this->finding($orphan)->kind);
    }

    public function test_eligibility_needs_two_consistent_runs_and_seven_days_and_drift_starts_again(): void
    {
        [$org, $request] = $this->request();
        $orphan = $this->upload($org, $request, record: false);
        $missing = $this->upload($org, $request, file: false);
        $this->reconcile();
        $this->assertSame(1, $this->finding($orphan)->observations);
        $this->reconcile();
        $this->assertSame(2, $this->finding($orphan)->observations);

        // Two consistent runs are not enough on their own: the first sighting must be at least seven days old.
        $eligible = fn (string $key) => $this->row(basename($key))['eligible'] ?? null;
        $this->assertFalse($eligible($orphan));
        $this->control->table('upload_reconciliation_findings')->whereIn('object_key', [$orphan, $missing])->update(['first_seen_at' => now()->subDays(8)]);
        $this->assertTrue($eligible($orphan));
        $this->assertFalse($eligible($missing), 'A missing file is reported, never eligible.');

        // The file gains its record (a late commit): the finding is dropped, and a later orphaning starts from one.
        $this->control->table('onboarding_documents')->insert(['id' => basename($orphan), 'org_id' => $org, 'request_id' => $request, 'name' => 'late.txt', 'object_key' => $orphan,
            'checksum' => hash('sha256', 'Synthetic document'), 'size' => 18, 'mime' => 'text/plain', 'scan_state' => 'clean']);
        $this->reconcile();
        $this->assertNull($this->finding($orphan));
        $this->control->table('onboarding_documents')->where('object_key', $orphan)->delete();
        $this->reconcile();
        $this->assertSame(1, $this->finding($orphan)->observations);
        $this->assertFalse($eligible($orphan));

        // A changed kind also starts again: the missing record's file reappears without its record.
        $this->control->table('onboarding_documents')->where('object_key', $missing)->delete();
        Storage::disk('onboarding')->put($missing, 'Synthetic document');
        $this->reconcile();
        $this->assertSame(['orphaned', 1], [$this->finding($missing)->kind, $this->finding($missing)->observations]);
    }

    public function test_unavailable_storage_records_a_failed_run_and_leaves_findings_untouched_then_retries(): void
    {
        [$org, $request] = $this->request();
        $orphan = $this->upload($org, $request, record: false);
        $this->reconcile();
        $this->reconcile();
        $before = $this->finding($orphan);

        $disk = Mockery::mock(Storage::disk('onboarding'))->makePartial();
        $driver = Mockery::mock(Storage::disk('onboarding')->getDriver());
        $driver->shouldReceive('listContents')->andThrow(UnableToListContents::atLocation('onboarding', true, new \RuntimeException('offline')));
        $disk->shouldReceive('getDriver')->andReturn($driver);
        Storage::set('onboarding', $disk);
        $result = $this->reconcile();

        $this->assertSame('failed', $result['status']);
        $run = $this->control->table('upload_reconciliation_runs')->orderByDesc('started_at')->first();
        $this->assertSame(['failed', 'Storage could not be listed'], [$run->status, $run->failure]);
        $after = $this->finding($orphan);
        $this->assertSame([$before->observations, $before->last_run], [$after->observations, $after->last_run]);

        // The next run, with storage back, carries on from the earlier count.
        Storage::fake('onboarding');
        Storage::disk('onboarding')->put($orphan, 'Synthetic document');
        $this->assertSame('completed', $this->reconcile()['status']);
        $this->assertSame($before->observations + 1, $this->finding($orphan)->observations);
    }

    public function test_tables_are_reachable_only_through_functions_and_the_report_needs_cleanup_review(): void
    {
        [$org, $request] = $this->request();
        $orphan = $this->upload($org, $request, record: false);
        $this->reconcile();
        $owner = User::factory()->create();
        foreach (['upload_reconciliation_runs', 'upload_reconciliation_findings'] as $table) {
            $this->assertFalse($this->control->selectOne("SELECT has_table_privilege('weavdash_app',?, 'SELECT') AS allowed", [$table])->allowed, $table);
        }
        $this->assertFalse(app(TenantContext::class)->run($owner->id, null, fn () => DB::selectOne("SELECT has_table_privilege('upload_reconciliation_findings','SELECT,INSERT,UPDATE,DELETE') AS allowed")->allowed));
        // Invalid results are refused rather than recorded.
        $this->expectExceptionMessage('Invalid reconciliation');
        DB::select('SELECT app_upload_reconcile_record(?::uuid,?,?::jsonb,0,0,0,NULL)', [(string) Str::uuid(), 'completed', json_encode([['key' => $orphan, 'kind' => 'deleted']])]);
    }

    public function test_the_administration_report_shows_counts_and_findings_to_cleanup_reviewers_only(): void
    {
        [$org, $request] = $this->request();
        $orphan = $this->upload($org, $request, record: false);
        $this->reconcile();
        $denied = $this->operator('{directory.read}');
        $this->verified($denied)->getJson('/admin/cleanup')->assertForbidden();
        $reviewer = $this->operator('{cleanup.review}');
        $report = $this->verified($reviewer)->getJson('/admin/cleanup')->assertOk()->json('reconciliation');
        $this->assertSame('completed', $report['last_run']['status']);
        $row = $this->row(basename($orphan), 'orphaned');
        $this->assertSame(['orphaned', $org, false], [$row['kind'], $row['org_id'], $row['eligible']]);
        $this->assertNotNull($row['workspace']);
        // No key, file name or content leaves the database through the report.
        $this->assertArrayNotHasKey('object_key', $row);
        $this->assertStringNotContainsString('Synthetic document', json_encode($report));
        $this->assertGreaterThanOrEqual(1, $report['counts']['orphaned']);
    }

    public function test_findings_are_filtered_and_paged_on_the_server_while_counts_ignore_the_filter(): void
    {
        [$org, $request] = $this->request();
        foreach (range(1, 27) as $ignored) {
            $this->upload($org, $request, record: false);
        }
        $this->reconcile();
        $this->verified($this->operator('{cleanup.review}'));
        $all = $this->getJson('/admin/cleanup')->assertOk()->json('reconciliation');
        $orphans = $this->getJson('/admin/cleanup?finding=orphaned')->assertOk();
        $this->assertSame('orphaned', $orphans->json('findingFilter'));
        $report = $orphans->json('reconciliation');
        $this->assertGreaterThanOrEqual(27, $report['total']);
        $this->assertSame($all['counts'], $report['counts'], 'Counts never follow the filter.');
        $this->assertSame($report['counts']['orphaned'], $report['total']);
        $this->assertCount(25, $report['findings']);
        $this->assertSame(['orphaned'], collect($report['findings'])->pluck('kind')->unique()->values()->all());
        $last = (int) ceil($report['total'] / 25);
        $tail = $this->getJson('/admin/cleanup?finding=orphaned&finding_page='.$last)->assertOk();
        $this->assertSame($last, $tail->json('findingPage'));
        $this->assertCount($report['total'] - ($last - 1) * 25, $tail->json('reconciliation.findings'));
        $this->getJson('/admin/cleanup?finding=deleted')->assertUnprocessable();
        $this->getJson('/admin/cleanup?finding_page=0')->assertUnprocessable();
    }

    /** This test's finding, found across pages: the shared database holds older findings ahead of it. */
    private function row(string $document, ?string $kind = null): ?array
    {
        $this->verified($this->operator('{cleanup.review}'));
        for ($page = 1; ; $page++) {
            $report = $this->getJson('/admin/cleanup?finding_page='.$page.($kind ? '&finding='.$kind : ''))->assertOk()->json('reconciliation');
            $row = collect($report['findings'])->firstWhere('document_id', $document);
            if ($row || $page * 25 >= $report['total']) {
                return $row;
            }
        }
    }

    private function operator(string $permissions): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $this->control->table('platform_grants')->insert(['user_id' => $user->id, 'permissions' => $permissions]);
        $this->control->table('users')->where('id', $user->id)->update(['admin_totp_secret' => encrypt(self::SECRET), 'admin_totp_confirmed_at' => now()]);

        return $user->refresh();
    }

    private function verified(User $user): static
    {
        return $this->actingAs($user)->withSession(['platform_mfa' => ['user' => $user->id, 'version' => 1, 'until' => time() + 900]]);
    }
}
