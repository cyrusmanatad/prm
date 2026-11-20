<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\Authenticator;
use App\Services\PrivacyExports;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BrandFixture;

class PrivacyClosureTest extends AcceptanceCase
{
    private const PASSWORD = 'SyntheticClosurePassword123!';

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.owner_mfa' => false]);
        Storage::fake('privacy-exports');
        Storage::fake('onboarding');
    }

    private function person(bool $enrolled = true): User
    {
        return User::factory()->create(['password' => Hash::make(self::PASSWORD), 'admin_totp_secret' => $enrolled ? encrypt(self::SECRET) : null, 'admin_totp_confirmed_at' => $enrolled ? now() : null]);
    }

    private function credentials(User $user): array
    {
        $this->control->table('users')->where('id', $user->id)->update(['admin_totp_counter' => null]);

        return ['password' => self::PASSWORD, 'code' => app(Authenticator::class)->code(self::SECRET, intdiv(time(), 30))];
    }

    private function preview(User $user, ?string $org = null): array
    {
        return $this->actingAs($user->refresh())->getJson('/account/closure'.($org ? '?scope=workspace&org_id='.$org : ''))->assertOk()->json('preview');
    }

    private function close(User $user, ?string $org = null): string
    {
        $preview = $this->preview($user, $org);

        return $this->actingAs($user->refresh())->postJson('/account/closure', [...$this->credentials($user), 'scope' => $org ? 'workspace' : 'account', 'org_id' => $org, 'manifest_hash' => $preview['hash'], 'reason' => 'Synthetic closure request'])
            ->assertCreated()->json('id');
    }

    private function cancel(User $user, string $id, int $version = 1): void
    {
        $this->actingAs($user->refresh())->postJson('/account/closure/'.$id.'/cancel', [...$this->credentials($user), 'version' => $version, 'reason' => 'Synthetic cancellation'])->assertOk();
    }

    private function lead(string $org): string
    {
        $id = (string) Str::uuid();
        $this->control->table('pipeline_entries')->insert(['id' => $id, 'org_id' => $org, 'brand_name' => 'Retained lead', 'currency' => 'PHP']);

        return $id;
    }

    public function test_account_closure_signs_out_restricts_future_sessions_and_cancellation_does_not_revive_exports_or_tokens(): void
    {
        $user = $this->person(false);
        $this->control->table('sessions')->insert(['id' => 'synthetic-'.Str::uuid(), 'user_id' => $user->id, 'payload' => 'synthetic', 'last_activity' => time()]);
        $this->control->table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'synthetic', 'created_at' => now()]);
        $archive = $this->actingAs($user)->postJson('/account/exports', ['scope' => 'account', 'password' => self::PASSWORD])->assertCreated()->json('id');
        $id = $this->close($user);
        $this->assertGuest();
        $this->assertSame(0, $this->control->table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(0, $this->control->table('password_reset_tokens')->where('email', $user->email)->count());
        $this->assertNotNull($this->control->table('privacy_exports')->where('id', $archive)->value('revoked_at'));
        $this->actingAs($user)->getJson('/workspaces')->assertForbidden();
        $this->getJson('/account/exports')->assertForbidden();
        $this->get('/workspaces')->assertRedirect('/account/closure');
        $this->getJson('/account/closure')->assertOk()->assertJsonPath('accountClosing', true)->assertJsonPath('requests.0.id', $id);
        $this->cancel($user, $id);
        $this->getJson('/workspaces')->assertOk();
        $this->getJson('/account/exports/'.$archive.'/download')->assertStatus(410);
        Storage::disk('privacy-exports')->assertExists($archive.'.zip');
        $this->assertSame(0, app(PrivacyExports::class)->purge()['failed']);
        Storage::disk('privacy-exports')->assertMissing($archive.'.zip');
        $this->assertSame('expired', $this->control->table('privacy_exports')->where('id', $archive)->value('state'));
        $row = $this->control->table('privacy_closure_requests')->where('id', $id)->first();
        $this->assertSame('cancelled', $row->state);
        $this->assertTrue($this->control->selectOne("SELECT not_before>=requested_at+interval '30 days'-interval '1 second' AS bounded FROM privacy_closure_requests WHERE id=?", [$id])->bounded);
        $this->assertSame(1, $this->control->table('users')->where('id', $user->id)->count());
    }

    public function test_workspace_closure_revokes_public_and_capability_access_and_keeps_finance_documents_and_private_leads(): void
    {
        $owner = $this->person();
        $org = app(Workspaces::class)->create($owner->id, 'Closing workspace');
        $other = app(Workspaces::class)->create($owner->id, 'Unaffected workspace');
        $lead = $this->lead($org);
        $otherLead = $this->lead($other);
        $kit = (string) Str::uuid();
        $link = (string) Str::uuid();
        $request = (string) Str::uuid();
        $requestToken = bin2hex(random_bytes(32));
        $session = (string) Str::uuid();
        $this->control->table('media_kits')->insert(['id' => $kit, 'org_id' => $org, 'title' => 'Published before closure', 'published' => true, 'consented_at' => now()]);
        $this->control->table('lead_links')->insert(['id' => $link, 'org_id' => $org, 'token_hash' => hash('sha256', $link), 'expires_at' => now()->addDay()]);
        $this->control->table('onboarding_requests')->insert(['id' => $request, 'org_id' => $org, 'pipeline_entry_id' => $lead, 'label' => 'Private onboarding', 'token_hash' => hash('sha256', $requestToken), 'expires_at' => now()->addDay()]);
        $this->control->table('onboarding_sessions')->insert(['id' => $session, 'org_id' => $org, 'request_id' => $request, 'token_hash' => hash('sha256', $session), 'expires_at' => now()->addDay()]);
        $doc = (string) Str::uuid();
        Storage::disk('onboarding')->put('retained/'.$doc, 'retained bytes');
        $this->control->table('onboarding_documents')->insert(['id' => $doc, 'org_id' => $org, 'request_id' => $request, 'name' => 'retained.txt', 'object_key' => 'retained/'.$doc, 'checksum' => hash('sha256', 'retained bytes'), 'size' => 14, 'mime' => 'text/plain', 'scan_state' => 'clean']);
        $invoice = (string) Str::uuid();
        $this->control->table('invoice_drafts')->insert(['id' => $invoice, 'org_id' => $org, 'recipient_name' => 'Retained finance', 'currency' => 'PHP', 'total_minor' => 0]);
        $id = $this->close($owner, $org);
        $this->actingAs($owner)->withSession(['active_org_id' => $org])->getJson('/pipeline')->assertForbidden();
        $this->get('/kits/'.$kit)->assertNotFound();
        $this->postJson('/onboarding/session', ['token' => $requestToken])->assertNotFound();
        $this->assertFalse($this->control->table('media_kits')->where('id', $kit)->value('published'));
        $this->assertNotNull($this->control->table('lead_links')->where('id', $link)->value('revoked_at'));
        $this->assertSame('revoked', $this->control->table('onboarding_requests')->where('id', $request)->value('status'));
        $this->assertSame(0, $this->control->table('onboarding_sessions')->where('id', $session)->count());
        $this->assertSame(1, $this->control->table('invoice_drafts')->where('id', $invoice)->count());
        Storage::disk('onboarding')->assertExists('retained/'.$doc);
        $this->withSession(['active_org_id' => $other])->getJson('/pipeline')->assertOk()->assertSee($otherLead);
        $this->cancel($owner, $id);
        $this->withSession(['active_org_id' => $org])->getJson('/pipeline')->assertOk()->assertSee($lead);
        $this->get('/kits/'.$kit)->assertNotFound();
        $this->assertSame('revoked', $this->control->table('onboarding_requests')->where('id', $request)->value('status'));
    }

    public function test_sole_workspace_owner_and_platform_operators_are_protected_and_other_roles_cannot_close_a_workspace(): void
    {
        $owner = $this->person();
        $org = app(Workspaces::class)->create($owner->id, 'Protected ownership');
        $preview = $this->preview($owner);
        $this->assertNotEmpty($preview['blockers']);
        $this->postJson('/account/closure', [...$this->credentials($owner), 'scope' => 'account', 'manifest_hash' => $preview['hash'], 'reason' => 'Try to close'])->assertUnprocessable();
        foreach (['admin', 'manager', 'member', 'viewer'] as $role) {
            $peer = $this->person();
            $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $peer->id, 'role' => $role]);
            $this->actingAs($peer)->getJson('/account/closure?scope=workspace&org_id='.$org)->assertForbidden();
            $this->postJson('/account/closure', [...$this->credentials($peer), 'scope' => 'workspace', 'org_id' => $org, 'manifest_hash' => str_repeat('a', 64), 'reason' => 'Try to close'])->assertForbidden();
        }
        $operator = $this->person();
        $this->control->table('platform_grants')->insert(['user_id' => $operator->id, 'permissions' => '{platform.owner}']);
        $this->assertNotEmpty($this->preview($operator)['blockers']);
        $this->actingAs($operator)->getJson('/account/closure?scope=workspace&org_id='.$org)->assertForbidden();
    }

    public function test_review_hash_and_request_version_prevent_stale_changes_and_wrong_credentials_never_restrict_access(): void
    {
        $owner = $this->person();
        $org = app(Workspaces::class)->create($owner->id, 'Stale review');
        $preview = $this->preview($owner, $org);
        $this->postJson('/account/closure', ['scope' => 'workspace', 'org_id' => $org, 'manifest_hash' => $preview['hash'], 'reason' => 'Try', 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->lead($org);
        $this->postJson('/account/closure', [...$this->credentials($owner), 'scope' => 'workspace', 'org_id' => $org, 'manifest_hash' => $preview['hash'], 'reason' => 'Stale preview'])->assertConflict()->assertJsonValidationErrors('version');
        $this->assertSame(0, $this->control->table('privacy_closure_requests')->where('org_id', $org)->count());
        $id = $this->close($owner, $org);
        $this->postJson('/account/closure/'.$id.'/cancel', [...$this->credentials($owner), 'version' => 99, 'reason' => 'Stale cancellation'])->assertConflict();
        $this->assertSame('cooling_off', $this->control->table('privacy_closure_requests')->where('id', $id)->value('state'));
    }

    public function test_account_closure_cancels_only_its_own_automation_and_does_not_resume_it_on_cancellation(): void
    {
        $owner = $this->person();
        $peer = $this->person();
        $org = app(Workspaces::class)->create($owner->id, 'Shared workspace');
        $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $peer->id, 'role' => 'owner']);
        $lead = $this->lead($org);
        $drafts = [];
        foreach ([$owner, $peer] as $person) {
            $this->control->table('automation_preferences')->insert(['org_id' => $org, 'actor_id' => $person->id, 'reminders' => true, 'follow_up_drafts' => true]);
            $draft = (string) Str::uuid();
            $this->control->table('follow_up_drafts')->insert(['id' => $draft, 'org_id' => $org, 'actor_id' => $person->id, 'pipeline_entry_id' => $lead, 'subject_version' => 1, 'body' => 'Private draft']);
            $drafts[$person->id] = $draft;
        }
        $id = $this->close($owner);
        $this->assertSame('cancelled', $this->control->table('follow_up_drafts')->where('id', $drafts[$owner->id])->value('state'));
        $this->assertSame('draft', $this->control->table('follow_up_drafts')->where('id', $drafts[$peer->id])->value('state'));
        $this->actingAs($peer)->withSession(['active_org_id' => $org])->getJson('/pipeline')->assertOk();
        $this->cancel($owner, $id);
        $this->assertSame('cancelled', $this->control->table('follow_up_drafts')->where('id', $drafts[$owner->id])->value('state'));
        $this->assertFalse($this->control->table('automation_preferences')->where(['org_id' => $org, 'actor_id' => $owner->id])->value('reminders'));
    }

    public function test_ended_partnerships_and_shared_leads_never_reappear_after_workspace_cancellation(): void
    {
        $brand = $this->person();
        $brandOrg = BrandFixture::create($brand->id, 'Closure Brand '.Str::uuid(), $this->control);
        $partner = $this->person();
        $partnerOrg = app(Workspaces::class)->create($partner->id, 'Closure Partner');
        $invitation = (string) Str::uuid();
        $relationship = (string) Str::uuid();
        $this->control->table('partner_invitations')->insert(['id' => $invitation, 'brand_org_id' => $brandOrg, 'recipient_email' => $partner->email, 'token_hash' => hash('sha256', $invitation), 'status' => 'accepted', 'expires_at' => now()->addDay()]);
        $this->control->table('partnerships')->insert(['id' => $relationship, 'brand_org_id' => $brandOrg, 'creator_org_id' => $partnerOrg, 'brand_name' => 'Brand', 'creator_name' => 'Partner', 'invitation_id' => $invitation]);
        $lead = $this->lead($partnerOrg);
        $registration = (string) Str::uuid();
        $this->control->table('lead_registrations')->insert(['id' => $registration, 'partnership_id' => $relationship, 'brand_org_id' => $brandOrg, 'creator_org_id' => $partnerOrg, 'pipeline_entry_id' => $lead, 'display_name' => 'Shared lead', 'stage' => 'lead', 'registered_by' => $partner->id, 'registered_at' => now(), 'shared_updated_at' => now()]);
        $id = $this->close($partner, $partnerOrg);
        $this->assertSame('revoked', $this->control->table('partnerships')->where('id', $relationship)->value('status'));
        $this->assertSame('withdrawn', $this->control->table('lead_registrations')->where('id', $registration)->value('state'));
        $this->cancel($partner, $id);
        app(TenantContext::class)->run($brand->id, $brandOrg, function () use ($registration) {
            $this->assertSame(0, DB::table('lead_registrations')->where('id', $registration)->count());
        });
    }

    public function test_no_account_mail_or_new_reset_token_is_created_during_closure(): void
    {
        Notification::fake();
        $user = $this->person(false);
        $id = $this->close($user);
        $user->sendPasswordResetNotification('never-send');
        $user->sendEmailVerificationNotification();
        Notification::assertNothingSent();
        $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();
        $this->assertSame(0, $this->control->table('password_reset_tokens')->where('email', $user->email)->count());
        $this->cancel($user, $id);
        $user->sendPasswordResetNotification('synthetic-after-cancel');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_private_manifest_and_closure_history_have_no_direct_runtime_access_or_foreign_self_service_access(): void
    {
        $user = $this->person(false);
        $foreign = $this->person(false);
        $id = $this->close($user);
        foreach (['privacy_closure_requests', 'privacy_closure_events'] as $table) {
            try {
                app(TenantContext::class)->run($foreign->id, null, fn () => DB::table($table)->count());
                $this->fail('Private closure tables must not be readable directly.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
            }
        }
        app(TenantContext::class)->run($foreign->id, null, function () {
            $this->assertSame('[]', DB::selectOne('SELECT app_privacy_history() AS data')->data);
        });
        $this->actingAs($foreign)->postJson('/account/closure/'.$id.'/cancel', [...$this->credentials($foreign), 'version' => 1, 'reason' => 'Foreign request'])->assertForbidden();
    }

    public function test_workspace_work_and_closure_serialize_in_both_lock_orders(): void
    {
        $owner = $this->person();
        $org = app(Workspaces::class)->create($owner->id, 'Concurrent workspace');
        $this->lead($org);
        $preview = $this->preview($owner, $org);
        $request = (string) Str::uuid();
        $closer = null;
        app(TenantContext::class)->run($owner->id, $org, function () use ($owner, $org, $preview, $request, &$closer) {
            $this->assertSame(1, DB::table('pipeline_entries')->where('org_id', $org)->count());
            $closer = $this->closureProbe('close', $owner->id, $org, $preview['hash'], $request);
            $this->assertBlocked($closer);
        });
        $this->assertSame('closed', $this->probeResult($closer)['outcome']);
        $this->cancel($owner, $request);
        $preview = $this->preview($owner, $org);
        $reader = null;
        app(TenantContext::class)->run($owner->id, null, function () use ($owner, $org, $preview, &$reader) {
            DB::select('SELECT app_privacy_close(?::uuid,?,?::uuid,?,?)', [(string) Str::uuid(), 'workspace', $org, $preview['hash'], 'Synthetic closure first']);
            $reader = $this->closureProbe('work', $owner->id, $org);
            $this->assertBlocked($reader);
        }, accessBarrier: false);
        $this->assertSame('denied', $this->probeResult($reader)['outcome']);
    }

    public function test_two_owners_cannot_both_close_accounts_and_leave_an_active_workspace_without_an_owner(): void
    {
        $first = $this->person();
        $second = $this->person();
        $org = app(Workspaces::class)->create($first->id, 'Two owners');
        $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $second->id, 'role' => 'owner']);
        $one = $this->preview($first);
        $two = $this->preview($second);
        $waiting = null;
        app(TenantContext::class)->run($first->id, null, function () use ($second, $one, $two, &$waiting) {
            DB::select('SELECT app_privacy_close(?::uuid,?,NULL,?,?)', [(string) Str::uuid(), 'account', $one['hash'], 'First owner closes']);
            $waiting = $this->closureProbe('close', $second->id, null, $two['hash'], (string) Str::uuid());
            $this->assertBlocked($waiting);
        }, accessBarrier: false);
        $this->assertSame('denied', $this->probeResult($waiting)['outcome']);
        $this->assertFalse(DB::selectOne('SELECT app_account_closing(?) AS closing', [$second->id])->closing);
    }

    public function test_direct_account_mail_cannot_cross_a_committed_closure_barrier(): void
    {
        $user = $this->person(false);
        $preview = $this->preview($user);
        $sender = null;
        app(TenantContext::class)->run($user->id, null, function () use ($user, $preview, &$sender) {
            DB::select('SELECT app_privacy_close(?::uuid,?,NULL,?,?)', [(string) Str::uuid(), 'account', $preview['hash'], 'Close before account mail']);
            $sender = $this->closureProbe('mail', $user->id);
            $this->assertBlocked($sender);
        }, accessBarrier: false);
        $result = $this->probeResult($sender);
        $this->assertSame('mail', $result['outcome']);
        $this->assertSame(0, $result['captured']);
        $other = $this->person(false);
        $preview = $this->preview($other);
        $closer = null;
        DB::transaction(function () use ($other, $preview, &$closer) {
            $this->assertTrue(DB::selectOne('SELECT app_privacy_mail_allowed(?) AS allowed', [$other->id])->allowed);
            $closer = $this->closureProbe('close', $other->id, null, $preview['hash'], (string) Str::uuid());
            $this->assertBlocked($closer);
            $other->sendPasswordResetNotification('synthetic-admitted-before-closure');
            $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        });
        $this->assertSame('closed', $this->probeResult($closer)['outcome']);
    }

    public function test_only_platform_owners_handle_metadata_reviews_and_verified_cancellation(): void
    {
        $user = $this->person(false);
        $id = $this->close($user);
        $scoped = $this->person();
        $this->control->table('platform_grants')->insert(['user_id' => $scoped->id, 'permissions' => '{directory.read}']);
        $this->actingAs($scoped)->withSession(['platform_mfa' => ['user' => $scoped->id, 'version' => 1, 'until' => time() + 900]])->getJson('/admin/privacy-requests')->assertForbidden();
        $operator = $this->person();
        $this->control->table('platform_grants')->insert(['user_id' => $operator->id, 'permissions' => '{platform.owner}']);
        $this->actingAs($operator)->withSession(['platform_mfa' => ['user' => $operator->id, 'version' => 1, 'until' => time() + 900]])->getJson('/admin/privacy-requests')->assertOk()->assertDontSee('manifest_hash')->assertDontSee('"manifest"', false);
        $update = fn (string $action, int $version, array $extra = []) => $this->postJson('/admin/privacy-requests/'.$id, [...$this->credentials($operator), 'action' => $action, 'version' => $version, 'reason' => 'Synthetic verified privacy handling', ...$extra]);
        $update('review', 1)->assertUnprocessable();
        $update('assign', 1)->assertOk();
        $update('review', 1)->assertConflict();
        $update('review', 2)->assertOk();
        $update('cancel', 3)->assertUnprocessable()->assertJsonValidationErrors('verification_reference');
        $this->control->table('platform_suspensions')->insert(['subject' => 'account', 'user_id' => $user->id, 'reason' => 'Independent moderation hold', 'suspended_by' => $operator->id]);
        $update('cancel', 3, ['verification_reference' => 'synthetic-case-verified-001'])->assertOk();
        $this->assertSame('cancelled', $this->control->table('privacy_closure_requests')->where('id', $id)->value('state'));
        $this->assertSame($operator->id, $this->control->table('privacy_closure_requests')->where('id', $id)->value('assigned_to'));
        $this->assertSame('synthetic-case-verified-001', $this->control->table('privacy_closure_events')->where(['request_id' => $id, 'event' => 'cancelled'])->value('verification_reference'));
        $this->actingAs($user)->getJson('/workspaces')->assertForbidden();
    }

    public function test_account_notice_admission_is_serialized_and_uncertainty_and_consumption_are_never_refunded(): void
    {
        $user = $this->person(false);
        $notice = $this->notice($user);
        $uncertain = $this->notice($user, 'uncertain');
        $receipt = (string) Str::uuid();
        $this->control->table('mail_budget_attempts')->insert(['id' => $receipt, 'category' => 'account', 'recipients' => 1, 'consumed_at' => now()]);
        $preview = $this->preview($user);
        $closer = null;
        DB::transaction(function () use ($user, $notice, $preview, &$closer) {
            $reservation = json_decode(DB::selectOne('SELECT app_account_notification_reserve_daily(?::uuid,true,0,0,false) AS data', [$notice])->data, true);
            $this->assertSame('reserved', $reservation['outcome']);
            // Both the reservation and the disclosure boundary own the account read barrier until commit.
            $closer = $this->closureProbe('close', $user->id, null, $preview['hash'], (string) Str::uuid());
            $this->assertBlocked($closer);
        });
        // A reservation changes the reviewed inventory: the waiting closer must ask for a new review.
        $this->assertSame('denied', $this->probeResult($closer)['outcome']);
        $this->close($user);
        $this->assertSame('sending', $this->control->table('account_notification_outbox')->where('id', $notice)->value('state'));
        DB::select('SELECT app_account_notification_finish(?::uuid,?)', [$notice, 'captured']);
        $this->assertSame('captured', $this->control->table('account_notification_outbox')->where('id', $notice)->value('state'));
        $this->assertSame('uncertain', $this->control->table('account_notification_outbox')->where('id', $uncertain)->value('state'));
        $this->assertNotNull($this->control->table('mail_budget_attempts')->where('id', $receipt)->value('consumed_at'));
        $other = $this->person(false);
        $pending = $this->notice($other);
        $preview = $this->preview($other);
        $sender = null;
        app(TenantContext::class)->run($other->id, null, function () use ($other, $pending, $preview, &$sender) {
            DB::select('SELECT app_privacy_close(?::uuid,?,NULL,?,?)', [(string) Str::uuid(), 'account', $preview['hash'], 'Closure before notice']);
            $sender = $this->closureProbe('account-notice', $other->id, null, '-', $pending);
            $this->assertBlocked($sender);
        }, accessBarrier: false);
        $this->assertSame('finished', $this->probeResult($sender)['outcome']);
        $this->assertSame('cancelled', $this->control->table('account_notification_outbox')->where('id', $pending)->value('state'));
    }

    public function test_an_owned_workspace_cannot_reopen_while_its_only_owner_has_an_active_account_closure(): void
    {
        $owner = $this->person();
        $org = app(Workspaces::class)->create($owner->id, 'Retained closed workspace');
        $workspaceRequest = $this->close($owner, $org);
        $accountRequest = $this->close($owner);
        $operator = $this->person();
        $this->control->table('platform_grants')->insert(['user_id' => $operator->id, 'permissions' => '{platform.owner}']);
        $this->actingAs($operator)->withSession(['platform_mfa' => ['user' => $operator->id, 'version' => 1, 'until' => time() + 900]])
            ->postJson('/admin/privacy-requests/'.$workspaceRequest, [...$this->credentials($operator), 'action' => 'cancel', 'version' => 1, 'reason' => 'Try to reopen', 'verification_reference' => 'synthetic-verified-case'])->assertUnprocessable();
        $this->cancel($owner, $accountRequest);
        $this->cancel($owner, $workspaceRequest);
        $this->withSession(['active_org_id' => $org])->getJson('/pipeline')->assertOk();
    }

    public function test_closing_accounts_cannot_receive_new_active_memberships_or_platform_grants(): void
    {
        $user = $this->person(false);
        $this->close($user);
        $owner = $this->person();
        $org = app(Workspaces::class)->create($owner->id, 'Grant guard');
        foreach (['memberships', 'platform_grants'] as $table) {
            try {
                $this->control->table($table)->insert($table === 'memberships' ? ['user_id' => $user->id, 'org_id' => $org, 'role' => 'owner'] : ['user_id' => $user->id, 'permissions' => '{platform.owner}']);
                $this->fail('Closing identities must not gain new active grants.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
            }
        }
    }

    private function closureProbe(string $action, int $actor, ?string $org = null, string $hash = '-', string $reference = '-'): Process
    {
        $process = new Process([PHP_BINARY, 'tests/Fixtures/privacy-closure-probe.php', $action, (string) $actor, $org ?? '-', $hash, $reference], base_path(), ['APP_ENV' => 'testing', 'TEST_MIGRATION_DB_PASSWORD' => false]);
        $process->setTimeout(15);
        $process->start();
        $this->eventually(fn () => str_contains($process->getOutput(), "\n"), 'Closure probe did not connect.');

        return $process;
    }

    private function notice(User $user, string $state = 'pending'): string
    {
        $request = (string) Str::uuid();
        $event = (string) Str::uuid();
        $notice = (string) Str::uuid();
        $this->control->table('brand_requests')->insert(['id' => $request, 'applicant_id' => $user->id, 'brand_name' => 'Synthetic request', 'website' => 'https://example.test', 'applicant_role' => 'Owner', 'intended_use' => 'Synthetic acceptance', 'status' => 'declined', 'decided_at' => now()]);
        $this->control->table('brand_request_events')->insert(['id' => $event, 'request_id' => $request, 'version' => 1, 'event' => 'declined', 'actor_id' => $user->id, 'reason' => 'Synthetic decline']);
        $this->control->table('account_notification_outbox')->insert(['id' => $notice, 'user_id' => $user->id, 'kind' => 'brand_request.declined', 'brand_request_id' => $request, 'event_id' => $event, 'request_version' => 1, 'state' => $state]);

        return $notice;
    }
}
