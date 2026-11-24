<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\Authenticator;
use App\Services\PrivacyExportData;
use App\Services\PrivacyExports;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PharData;
use Symfony\Component\Process\Process;
use Tests\Support\BrandFixture;

class PrivacyExportTest extends AcceptanceCase
{
    private const PASSWORD = 'SyntheticPrivacyPassword123!';

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('privacy-exports');
        Storage::fake('onboarding');
        config(['security.owner_mfa' => false]);
    }

    private function person(bool $enrolled = false): User
    {
        return User::factory()->create(['password' => Hash::make(self::PASSWORD),
            'admin_totp_secret' => $enrolled ? encrypt(self::SECRET) : null, 'admin_totp_confirmed_at' => $enrolled ? now() : null]);
    }

    private function credentials(User $user): array
    {
        $this->control->table('users')->where('id', $user->id)->update(['admin_totp_counter' => null]);

        return ['password' => self::PASSWORD, 'code' => app(Authenticator::class)->code(self::SECRET, intdiv(time(), 30))];
    }

    private function export(User $user, ?string $org = null): string
    {
        return $this->actingAs($user->refresh())->postJson('/account/exports', [...$this->credentials($user), 'scope' => $org ? 'workspace' : 'account', 'org_id' => $org])
            ->assertCreated()->json('id');
    }

    private function archive(string $id): PharData
    {
        return new PharData(Storage::disk('privacy-exports')->path($id.'.zip'));
    }

    private function archiveJson(string $id, string $file): array
    {
        return json_decode($this->archive($id)[$file]->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function lead(string $org, array $extra = []): string
    {
        $id = (string) Str::uuid();
        $this->control->table('pipeline_entries')->insert(['id' => $id, 'org_id' => $org, 'brand_name' => 'Private client', 'currency' => 'USD', ...$extra]);

        return $id;
    }

    public function test_personal_archive_is_private_allowlisted_and_downloadable_with_an_expiry_and_audit(): void
    {
        $owner = $this->person();
        $foreign = $this->person();
        $this->actingAs($owner)->postJson('/account/exports', ['scope' => 'account', 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $id = $this->export($owner);
        $account = $this->archiveJson($id, 'data/account.json')[0];
        $this->assertSame($owner->email, $account['email']);
        // The personal accent is the account's own profile preference.
        $this->assertSame('burgundy', $account['appearance']);
        $this->assertArrayNotHasKey('password', $account);
        $this->assertArrayNotHasKey('admin_totp_secret', $account);
        $this->assertStringNotContainsString($foreign->email, $this->archive($id)['data/account.json']->getContent());
        $manifest = $this->archiveJson($id, 'manifest.json');
        $this->assertSame(1, $manifest['schema_version']);
        $this->assertSame('account', $manifest['scope']);
        foreach ($manifest['files'] as $file) {
            $this->assertSame($file['sha256'], hash('sha256', $this->archive($id)[$file['file']]->getContent()));
        }
        $response = $this->get('/account/exports/'.$id.'/download')->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('PK', substr($response->getContent(), 0, 2));
        $this->assertSame(['requested', 'ready', 'downloaded'], $this->control->table('privacy_export_events')->where('export_id', $id)->orderBy('occurred_at')->pluck('event')->all());
        $this->assertTrue($this->control->selectOne("SELECT expires_at>created_at AND expires_at<=created_at+interval '24 hours 1 second' AS bounded FROM privacy_exports WHERE id=?", [$id])->bounded);
        $this->flushSession();
        $this->actingAs($foreign)->getJson('/account/exports/'.$id.'/download')->assertNotFound();
        $this->getJson('/account/exports')->assertOk()->assertJsonCount(0, 'exports');
    }

    public function test_workspace_requires_owner_and_fresh_mfa_even_when_general_enforcement_is_off(): void
    {
        $owner = $this->person();
        $org = app(Workspaces::class)->create($owner->id, 'Export owner');
        $this->actingAs($owner)->postJson('/account/exports', ['scope' => 'workspace', 'org_id' => $org, 'password' => self::PASSWORD])->assertForbidden();
        $this->control->table('users')->where('id', $owner->id)->update(['admin_totp_secret' => encrypt(self::SECRET), 'admin_totp_confirmed_at' => now()]);
        $owner->refresh();
        $this->actingAs($owner)->postJson('/account/exports', ['scope' => 'workspace', 'org_id' => $org, 'password' => self::PASSWORD, 'code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->export($owner, $org);
        foreach (['admin', 'manager', 'member', 'viewer'] as $role) {
            $peer = $this->person(true);
            $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $peer->id, 'role' => $role]);
            $this->actingAs($peer)->postJson('/account/exports', [...$this->credentials($peer), 'scope' => 'workspace', 'org_id' => $org])->assertForbidden();
        }
        $operator = $this->person(true);
        $this->control->table('platform_grants')->insert(['user_id' => $operator->id, 'permissions' => '{platform.owner}']);
        $this->actingAs($operator)->postJson('/account/exports', [...$this->credentials($operator), 'scope' => 'workspace', 'org_id' => $org])->assertForbidden();
    }

    public function test_workspace_export_has_all_rows_finance_and_safe_documents_without_foreign_or_private_drafts(): void
    {
        $owner = $this->person(true);
        $org = app(Workspaces::class)->create($owner->id, 'Archive workspace');
        $peer = $this->person();
        $foreign = app(Workspaces::class)->create($peer->id, 'Foreign workspace');
        $this->lead($foreign, ['brand_name' => 'Hidden foreign client']);
        for ($i = 0; $i < 105; $i++) {
            $lead = $this->lead($org, ['brand_name' => $i === 0 ? '=DANGEROUS()' : 'Client '.$i]);
        }
        $this->control->table('automation_preferences')->insert(['org_id' => $org, 'actor_id' => $owner->id]);
        $this->control->table('follow_up_drafts')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'actor_id' => $owner->id, 'pipeline_entry_id' => $lead, 'subject_version' => 1, 'body' => 'Private actor draft']);
        $request = (string) Str::uuid();
        $this->control->table('onboarding_requests')->insert(['id' => $request, 'org_id' => $org, 'pipeline_entry_id' => $lead, 'label' => 'Document request', 'token_hash' => hash('sha256', $request), 'expires_at' => now()->addDay()]);
        $docs = [];
        foreach (['clean', 'pending', 'rejected', 'unscanned'] as $state) {
            $doc = (string) Str::uuid();
            $key = 'secret-storage-key/'.$doc;
            Storage::disk('onboarding')->put($key, 'Original document');
            $this->control->table('onboarding_documents')->insert(['id' => $doc, 'org_id' => $org, 'request_id' => $request, 'name' => '../../unsafe.txt', 'object_key' => $key, 'checksum' => hash('sha256', 'Original document'), 'size' => strlen('Original document'), 'mime' => 'text/plain', 'scan_state' => $state]);
            $docs[$state] = $doc;
        }
        $this->control->table('onboarding_submissions')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'request_id' => $request, 'revision' => 1, 'snapshot' => json_encode(['business_name' => 'Allowed snapshot', 'object_key' => 'SECRET_INJECTED', 'documents' => [['id' => $docs['clean'], 'checksum' => 'test', 'secret' => 'SECRET_INJECTED']]]), 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'snapshot')]);
        $invoice = (string) Str::uuid();
        $this->control->table('invoice_drafts')->insert(['id' => $invoice, 'org_id' => $org, 'recipient_name' => 'Allowed finance', 'currency' => 'USD', 'total_minor' => 0]);
        $id = $this->export($owner, $org);
        $manifest = $this->archiveJson($id, 'manifest.json');
        $this->assertSame(105, $manifest['counts']['pipeline_entries']);
        $this->assertSame(1, $manifest['counts']['invoice_drafts']);
        $this->assertCount(3, $manifest['excluded_documents']);
        $archive = $this->archive($id);
        $this->assertSame('Original document', $archive['documents/'.$docs['clean'].'.txt']->getContent());
        $this->assertStringContainsString("'=DANGEROUS()", $archive['data/pipeline_entries.csv']->getContent());
        $this->assertStringNotContainsString('SECRET_INJECTED', $archive['data/onboarding_submissions.json']->getContent());
        $this->assertStringNotContainsString('object_key', $archive['data/onboarding_documents.json']->getContent());
        $this->assertFalse(isset($archive['data/follow_up_drafts.json']));
        $this->assertStringNotContainsString('Hidden foreign client', $archive['data/pipeline_entries.json']->getContent());
        $this->getJson('/account/exports/'.$id.'/download')->assertOk();
        // A changed scan status prevents an older archive being downloaded as if still authorized.
        $this->control->table('onboarding_documents')->where('id', $docs['clean'])->update(['scan_state' => 'rejected']);
        $this->getJson('/account/exports/'.$id.'/download')->assertConflict();
    }

    public function test_personal_automation_is_actor_only_and_archives_invalidate_when_membership_changes(): void
    {
        $owner = $this->person();
        $peer = $this->person();
        $org = app(Workspaces::class)->create($owner->id, 'Personal automation');
        $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $peer->id, 'role' => 'member']);
        $lead = $this->lead($org);
        foreach ([$owner, $peer] as $person) {
            $this->control->table('automation_preferences')->insert(['org_id' => $org, 'actor_id' => $person->id]);
            $this->control->table('follow_up_drafts')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'actor_id' => $person->id, 'pipeline_entry_id' => $lead, 'subject_version' => 1, 'body' => $person->id === $owner->id ? 'My private draft' : 'Foreign private draft']);
        }
        $id = $this->export($owner);
        $drafts = $this->archive($id)['data/follow_up_drafts_'.$org.'.json']->getContent();
        $this->assertStringContainsString('My private draft', $drafts);
        $this->assertStringNotContainsString('Foreign private draft', $drafts);
        $this->control->table('memberships')->where(['org_id' => $org, 'user_id' => $owner->id])->update(['revoked_at' => now()]);
        $this->getJson('/account/exports/'.$id.'/download')->assertConflict();
    }

    public function test_download_rechecks_ownership_verification_password_and_suspension(): void
    {
        $owner = $this->person(true);
        $org = app(Workspaces::class)->create($owner->id, 'Revocable export');
        $id = $this->export($owner, $org);
        $this->withSession(['privacy_verified' => null])->getJson('/account/exports/'.$id.'/download')->assertUnprocessable()->assertJsonValidationErrors('verification');
        $this->postJson('/account/exports/verify', $this->credentials($owner))->assertOk();
        $this->getJson('/account/exports/'.$id.'/download')->assertOk();
        $this->control->table('users')->where('id', $owner->id)->update(['password' => Hash::make('ChangedPassword123!')]);
        $this->getJson('/account/exports/'.$id.'/download')->assertUnprocessable();
        $this->control->table('users')->where('id', $owner->id)->update(['password' => Hash::make(self::PASSWORD)]);
        $this->postJson('/account/exports/verify', $this->credentials($owner))->assertOk();
        $this->control->table('memberships')->where(['org_id' => $org, 'user_id' => $owner->id])->update(['role' => 'admin']);
        $this->getJson('/account/exports/'.$id.'/download')->assertForbidden();
        $this->control->table('platform_suspensions')->insert(['subject' => 'account', 'user_id' => $owner->id, 'reason' => 'Synthetic suspension', 'suspended_by' => $owner->id]);
        $this->getJson('/account/exports/'.$id.'/download')->assertForbidden();
    }

    public function test_expired_failed_and_interrupted_files_are_reconciled_without_touching_active_archives(): void
    {
        app(PrivacyExports::class)->purge();
        $user = $this->person();
        $id = $this->export($user);
        // A failed build has a committed receipt and may have either/both artifact names.
        $failed = (string) Str::uuid();
        $stale = (string) Str::uuid();
        $this->control->table('privacy_exports')->insert([
            ['id' => $failed, 'actor_id' => $user->id, 'scope' => 'account', 'state' => 'failed', 'created_at' => now(), 'expires_at' => now()->addDay()],
            ['id' => $stale, 'actor_id' => $user->id, 'scope' => 'account', 'state' => 'building', 'created_at' => now()->subHour(), 'expires_at' => now()->addHours(23)],
        ]);
        Storage::disk('privacy-exports')->put($failed.'.zip', 'failed artifact');
        Storage::disk('privacy-exports')->put($stale.'.part.zip', 'interrupted artifact');
        $this->assertSame(['removed' => 2, 'failed' => 0], app(PrivacyExports::class)->purge());
        Storage::disk('privacy-exports')->assertExists($id.'.zip');
        Storage::disk('privacy-exports')->assertMissing($failed.'.zip');
        Storage::disk('privacy-exports')->assertMissing($stale.'.part.zip');
        $this->assertSame(['removed' => 0, 'failed' => 0], app(PrivacyExports::class)->purge());
        $this->withSession(['privacy_verified' => null])->getJson('/account/exports/'.$id.'/download')->assertUnprocessable();
    }

    public function test_current_data_changes_and_tampered_archives_are_refused(): void
    {
        $user = $this->person();
        $id = $this->export($user);
        Storage::disk('privacy-exports')->put($id.'.zip', 'tampered');
        $this->getJson('/account/exports/'.$id.'/download')->assertConflict();
        $next = $this->export($user);
        $this->control->table('users')->where('id', $user->id)->update(['name' => 'Changed name']);
        $this->getJson('/account/exports/'.$next.'/download')->assertConflict();
    }

    public function test_brand_exports_never_include_private_partner_crm_and_refuse_withdrawn_shares(): void
    {
        $brand = $this->person(true);
        $brandOrg = BrandFixture::create($brand->id, 'Privacy Brand '.Str::uuid(), $this->control);
        $partner = $this->person();
        $partnerOrg = app(Workspaces::class)->create($partner->id, 'Privacy Partner');
        $invitation = (string) Str::uuid();
        $relationship = (string) Str::uuid();
        $this->control->table('partner_invitations')->insert(['id' => $invitation, 'brand_org_id' => $brandOrg, 'recipient_email' => $partner->email, 'token_hash' => hash('sha256', $invitation), 'status' => 'accepted', 'expires_at' => now()->addDay()]);
        $this->control->table('partnerships')->insert(['id' => $relationship, 'brand_org_id' => $brandOrg, 'creator_org_id' => $partnerOrg, 'brand_name' => 'Privacy Brand', 'creator_name' => 'Privacy Partner', 'invitation_id' => $invitation]);
        $lead = $this->lead($partnerOrg, ['notes' => 'Never share these private notes', 'contact_email' => 'private-contact@example.test']);
        $registration = (string) Str::uuid();
        $this->control->table('lead_registrations')->insert(['id' => $registration, 'partnership_id' => $relationship, 'brand_org_id' => $brandOrg, 'creator_org_id' => $partnerOrg, 'pipeline_entry_id' => $lead, 'display_name' => 'Explicitly shared label', 'stage' => 'lead', 'registered_by' => $partner->id, 'registered_at' => now(), 'shared_updated_at' => now()]);
        $id = $this->export($brand, $brandOrg);
        $this->assertSame([], $this->archiveJson($id, 'data/pipeline_entries.json'));
        $shared = $this->archive($id)['data/lead_registrations.json']->getContent();
        $this->assertStringContainsString('Explicitly shared label', $shared);
        $this->assertStringNotContainsString('private-contact', $shared);
        $this->assertStringNotContainsString('Never share', $shared);
        $this->getJson('/account/exports/'.$id.'/download')->assertOk();
        // A materialization that owns the sharing locks makes withdrawal wait for its transaction.
        $withdraw = null;
        app(TenantContext::class)->run($brand->id, $brandOrg, function () use ($brand, $brandOrg, $partner, $partnerOrg, $registration, &$withdraw) {
            $data = app(PrivacyExportData::class)->collect($brand->id, $brandOrg);
            $this->assertCount(1, $data['lead_registrations']);
            $withdraw = $this->privacyProbe('withdraw', $partner->id, $partnerOrg, $registration);
            $this->assertBlocked($withdraw);
        }, snapshot: true);
        $this->assertSame('withdrawn', $this->probeResult($withdraw)['outcome']);
        $this->getJson('/account/exports/'.$id.'/download')->assertConflict();
        // If withdrawal owns the row first, a waiting snapshot cannot release the pre-withdrawal copy.
        $this->control->table('lead_registrations')->where('id', $registration)->update(['state' => 'active', 'withdrawn_at' => null, 'withdrawn_reason' => null, 'version' => 1]);
        $this->control->beginTransaction();
        $this->control->table('lead_registrations')->where('id', $registration)->update(['state' => 'withdrawn', 'withdrawn_at' => now(), 'withdrawn_reason' => 'partner', 'version' => 2]);
        $reader = $this->privacyProbe('collect', $brand->id, $brandOrg, $registration);
        $this->assertBlocked($reader);
        $this->control->commit();
        $result = $this->probeResult($reader);
        $this->assertSame('denied', $result['outcome']);
        $this->assertSame('40001', $result['code']);
    }

    private function privacyProbe(string $action, int $actor, string $org, string $registration): Process
    {
        $process = new Process([PHP_BINARY, 'tests/Fixtures/privacy-export-probe.php', $action, (string) $actor, $org, $registration], base_path(), ['APP_ENV' => 'testing', 'TEST_MIGRATION_DB_PASSWORD' => false]);
        $process->setTimeout(15);
        $process->start();
        $this->eventually(fn () => str_contains($process->getOutput(), "\n"), 'Privacy probe did not connect.');

        return $process;
    }

    public function test_expired_archive_is_denied_and_storage_failures_remain_retryable(): void
    {
        app(PrivacyExports::class)->purge();
        $user = $this->person();
        $id = $this->export($user);
        $expired = (string) Str::uuid();
        $receipt = $this->control->table('privacy_exports')->where('id', $id)->first();
        $this->control->table('privacy_exports')->insert(['id' => $expired, 'actor_id' => $user->id, 'scope' => 'account', 'state' => 'ready', 'fingerprint' => $receipt->fingerprint, 'checksum' => $receipt->checksum, 'size' => $receipt->size, 'created_at' => now()->subHours(25), 'expires_at' => now()->subHour()]);
        Storage::disk('privacy-exports')->put($expired.'.zip', 'expired archive');
        $this->getJson('/account/exports/'.$expired.'/download')->assertStatus(410);
        $manager = Storage::getFacadeRoot();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->with($expired.'.zip')->once()->andReturnTrue();
        $disk->shouldReceive('delete')->with($expired.'.zip')->once()->andThrow(new \RuntimeException('Synthetic storage outage'));
        Storage::shouldReceive('disk')->with('privacy-exports')->andReturn($disk);
        $this->assertSame(['removed' => 0, 'failed' => 1], app(PrivacyExports::class)->purge());
        Storage::swap($manager);
        $this->assertSame('ready', $this->control->table('privacy_exports')->where('id', $expired)->value('state'));
        $this->assertSame(['removed' => 1, 'failed' => 0], app(PrivacyExports::class)->purge());
        Storage::disk('privacy-exports')->assertMissing($expired.'.zip');
        $this->assertSame('expired', $this->control->table('privacy_exports')->where('id', $expired)->value('state'));
        Storage::disk('privacy-exports')->assertExists($id.'.zip');
    }

    public function test_the_runtime_cannot_read_other_receipts_mutate_scope_or_inspect_security_secrets(): void
    {
        $owner = $this->person();
        $foreign = $this->person();
        $id = $this->export($owner);
        app(TenantContext::class)->run($foreign->id, null, function () use ($id) {
            $this->assertSame(0, DB::table('privacy_exports')->where('id', $id)->count());
            $this->assertSame(0, DB::table('privacy_export_events')->where('export_id', $id)->count());
            $this->assertSame(0, DB::table('account_security_events')->count());
            $this->assertSame(0, DB::table('account_recovery_codes')->count());
            $this->assertFalse(DB::selectOne('SELECT app_privacy_export_cleanup(?::uuid,true) AS done', [$id])->done);
        });
        try {
            app(TenantContext::class)->run($owner->id, null, fn () => DB::table('privacy_exports')->where('id', $id)->update(['actor_id' => $foreign->id]));
            $this->fail('Scope mutation must fail.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        try {
            app(TenantContext::class)->run($owner->id, null, fn () => DB::table('privacy_exports')->insert(['id' => (string) Str::uuid(), 'actor_id' => $owner->id, 'scope' => 'account', 'created_at' => now()->addYear(), 'expires_at' => now()->addYear()->addDay()]));
            $this->fail('A future creation time must not extend an archive lifetime.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->errorInfo[0]);
        }
        try {
            app(TenantContext::class)->run($owner->id, null, fn () => DB::table('privacy_exports')->where('id', $id)->update(['state' => 'expired']));
            $this->fail('Only successful maintenance may finish file cleanup.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->errorInfo[0]);
        }
    }

    public function test_safety_limit_returns_an_explicit_failure_receipt_instead_of_a_partial_export(): void
    {
        $owner = $this->person(true);
        $org = app(Workspaces::class)->create($owner->id, 'Large export');
        $this->control->insert("INSERT INTO pipeline_entries(id,org_id,brand_name,currency) SELECT gen_random_uuid(),?::uuid,'Bulk client','USD' FROM generate_series(1,?)", [$org, PrivacyExportData::MAX_ROWS + 1]);
        $this->actingAs($owner)->postJson('/account/exports', [...$this->credentials($owner), 'scope' => 'workspace', 'org_id' => $org])->assertUnprocessable()->assertJsonValidationErrors('export');
        $receipt = $this->control->table('privacy_exports')->where('actor_id', $owner->id)->first();
        $this->assertSame('failed', $receipt->state);
        Storage::disk('privacy-exports')->assertMissing($receipt->id.'.zip');
    }
}
