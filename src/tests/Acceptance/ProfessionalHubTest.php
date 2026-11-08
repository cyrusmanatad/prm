<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\BusinessSchedules;
use App\Services\NotificationOutbox;
use App\Services\PlatformAdministration;
use App\Services\ScheduledNotifications;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

class ProfessionalHubTest extends AcceptanceCase
{
    private const RESOURCES = ['pipeline_entries', 'brand_contacts', 'reminders', 'onboarding_requests', 'outreach_templates', 'invoice_drafts', 'manual_payments'];

    private function professional(): array
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Independent consultant', experience: 'professional');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);

        return [$user, $org];
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $this->control->table('platform_grants')->insert(['user_id' => $user->id, 'permissions' => '{directory.read,plans.assign}']);
        $this->control->table('users')->where('id', $user->id)->update(['admin_totp_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'admin_totp_confirmed_at' => now()]);

        return $user->refresh();
    }

    private function assignment(string $org, int $version = 0, string $state = 'active', int $quota = 50): array
    {
        return ['workspace' => $org, 'expected_version' => $version, 'name' => 'Synthetic professional '.Str::uuid(), 'reason' => 'Synthetic acceptance activation',
            'starts_at' => $state === 'scheduled' ? now()->addDay()->toIso8601String() : now()->subDays(2)->toIso8601String(),
            'ends_at' => $state === 'expired' ? now()->subDay()->toIso8601String() : now()->addDays(2)->toIso8601String(),
            'quotas' => array_fill_keys(self::RESOURCES, $quota)];
    }

    private function activate(string $org, int $version = 0, string $state = 'active', int $quota = 50): void
    {
        $operator = $this->operator();
        $this->actingAs($operator)->withSession(['platform_mfa' => ['user' => $operator->id, 'version' => 1, 'until' => time() + 900]])
            ->postJson('/admin/assignments', $this->assignment($org, $version, $state, $quota))->assertOk()->assertJsonPath('version', $version + 1);
    }

    private function lead(string $name = 'Individual Client'): array
    {
        return ['brand_name' => $name, 'contact_name' => 'Client', 'contact_email' => 'client@example.test', 'notes' => 'private', 'value_minor' => '100', 'currency' => 'USD'];
    }

    public function test_pending_requires_activation_and_existing_partner_behavior_is_unchanged(): void
    {
        [$user, $org] = $this->professional();
        $this->getJson('/professional')->assertOk()->assertJsonPath('subscription.state', 'pending activation');
        $this->postJson('/pipeline', $this->lead())->assertUnprocessable();
        $this->getJson('/professional/exports/leads')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->getJson('/admin')->assertForbidden();
        $this->postJson('/workspaces', ['name' => 'Additional professional', 'kind' => 'creator', 'experience' => 'professional'])->assertCreated();
        $partner = app(Workspaces::class)->create($user->id, 'Existing partner');
        $this->withSession(['active_org_id' => $partner])->postJson('/pipeline', $this->lead())->assertCreated();
        $this->getJson('/professional')->assertNotFound();
        $this->withSession(['active_org_id' => $org])->getJson('/professional/exports/leads')->assertOk()->assertDontSee('Individual Client');
    }

    public function test_states_quotas_renewal_reading_and_owner_only_exports(): void
    {
        [$user, $org] = $this->professional();
        $this->activate($org, state: 'scheduled');
        $this->actingAs($user)->getJson('/professional')->assertJsonPath('subscription.state', 'scheduled');
        $this->postJson('/pipeline', $this->lead())->assertUnprocessable();
        $this->activate($org, 1, quota: 2);
        $this->actingAs($user)->postJson('/pipeline', $this->lead('Jane Individual'))->assertCreated();
        $this->postJson('/pipeline', $this->lead('Company Business'))->assertCreated();
        $this->postJson('/pipeline', $this->lead())->assertUnprocessable();
        $this->activate($org, 2, 'expired');
        $this->actingAs($user)->getJson('/professional/leads')->assertOk()->assertSee('Jane Individual')->assertSee('Company Business');
        $this->getJson('/professional/exports/leads')->assertOk()->assertSee('Jane Individual');
        foreach (['contacts', 'reminders', 'invoices', 'payments'] as $resource) {
            $this->getJson('/professional/exports/'.$resource)->assertOk();
        }
        $member = User::factory()->create();
        $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $member->id, 'role' => 'member']);
        $this->actingAs($member)->getJson('/professional/exports/invoices')->assertForbidden();
        $this->getJson('/professional/exports/payments')->assertForbidden();
        $this->getJson('/professional/exports/leads')->assertOk();
        $this->activate($org, 3);
        $this->actingAs($user)->postJson('/pipeline', $this->lead())->assertCreated();
    }

    public function test_mfa_grants_verification_stale_assignments_and_atomic_audit(): void
    {
        [, $org] = $this->professional();
        $operator = $this->operator();
        $this->actingAs($operator)->getJson('/admin')->assertForbidden();
        $this->postJson('/admin/assignments', $this->assignment($org))->assertForbidden();
        $this->withSession(['platform_mfa' => ['user' => $operator->id, 'version' => 1, 'until' => time() + 900]])->getJson('/admin')->assertOk()->assertJsonMissingPath('workspaces.0.notes');
        $data = $this->assignment($org);
        $this->postJson('/admin/assignments', $data)->assertOk();
        $this->postJson('/admin/assignments', $data)->assertConflict();
        $this->assertSame(1, $this->control->table('pilot_assignments')->where('org_id', $org)->count());
        $this->assertSame(1, $this->control->table('platform_audit')->where('target', $org)->where('event', 'plan.assigned')->count());
        $this->control->table('platform_grants')->where('user_id', $operator->id)->update(['revoked_at' => now(), 'version' => 2]);
        $this->getJson('/admin')->assertForbidden();
        $this->postJson('/admin/assignments', $this->assignment($org, 1))->assertForbidden();
    }

    public function test_totp_enrollment_verification_replay_and_stale_step_up(): void
    {
        $operator = User::factory()->create(['password' => Hash::make('SyntheticPassword123!')]);
        $this->control->table('platform_grants')->insert(['user_id' => $operator->id, 'permissions' => '{platform.owner}']);
        $this->actingAs($operator)->get('/admin/security')->assertOk();
        $this->post('/admin/security/enroll', ['password' => 'SyntheticPassword123!'])->assertOk();
        $pending = session('platform_enrollment');
        $code = app(PlatformAdministration::class)->code(decrypt($pending['secret']), intdiv(time(), 30));
        $this->post('/admin/security/verify', ['password' => 'SyntheticPassword123!', 'code' => $code])->assertRedirect('/admin');
        $this->getJson('/admin')->assertOk();
        $this->postJson('/admin/security/verify', ['password' => 'SyntheticPassword123!', 'code' => $code])->assertUnprocessable();
        $this->withSession(['platform_mfa' => ['user' => $operator->id, 'version' => 1, 'until' => time() - 1]])->getJson('/admin')->assertForbidden();
    }

    public function test_database_writes_are_denied_without_activation_and_expiry_at_commit_rolls_back(): void
    {
        [$user,$org] = $this->professional();
        try {
            app(TenantContext::class)->run($user->id, $org, fn () => DB::table('brand_contacts')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'name' => 'Direct SQL']));
            $this->fail('Direct SQL insert bypassed subscription');
        } catch (\PDOException $error) {
            $this->assertSame('P0004', $error->errorInfo[0]);
        }
        $this->activate($org);
        $this->control->table('pilot_assignments')->where('org_id', $org)->update(['ends_at' => now()->addSeconds(2)]);
        try {
            app(TenantContext::class)->run($user->id, $org, function () use ($org) {
                DB::table('brand_contacts')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'name' => 'Expires in transaction']);
                DB::select('SELECT pg_sleep(2.2)');
            });
            $this->fail('Expired transaction committed');
        } catch (\PDOException $error) {
            $this->assertSame('P0004', $error->errorInfo[0]);
        }
        $this->assertSame(0, $this->control->table('brand_contacts')->where('org_id', $org)->count());
    }

    public function test_expired_public_profile_stays_readable_and_capture_is_paused(): void
    {
        [$user,$org] = $this->professional();
        $this->activate($org);
        $this->actingAs($user)->putJson('/media-kit', ['title' => 'Consulting portfolio', 'bio' => 'Independent', 'website' => '', 'published' => true, 'consent' => true, 'version' => 0])->assertOk();
        $kit = $this->control->table('media_kits')->where('org_id', $org)->value('id');
        $link = $this->postJson('/lead-links')->assertCreated()->json();
        $this->activate($org, 1, 'expired');
        $this->get('/kits/'.$kit)->assertOk()->assertSee('Consulting portfolio');
        $token = explode('#', $link['url'])[1];
        $this->postJson('/lead', ['token' => $token, 'idempotency_key' => (string) Str::uuid(), 'brand_name' => 'Public individual', 'contact_name' => 'Name', 'email' => 'client@example.test', 'consent' => true])->assertUnprocessable()->assertDontSee('subscription');
        $this->actingAs($user)->deleteJson('/lead-links/'.$link['id'])->assertOk();
    }

    public function test_registration_intent_survives_verification_and_existing_identity_switching(): void
    {
        $email = 'professional-'.Str::uuid().'@example.test';
        $this->postJson('/professional/register', ['name' => 'Independent', 'email' => $email, 'password' => 'SyntheticPassword123!', 'password_confirmation' => 'SyntheticPassword123!'])->assertCreated();
        $user = User::where('email', $email)->firstOrFail();
        $this->assertSame('professional', $user->registration_workspace_experience);
        $this->get('/professional')->assertRedirect('/verify-email');
        $user->markEmailAsVerified();
        $this->actingAs($user->fresh());
        $this->withSession(['portal' => null])->get('/portal/enter')->assertRedirect('/professional');
        $org = $this->control->table('memberships')->where('user_id', $user->id)->value('org_id');
        $this->assertSame('professional', $this->control->table('organizations')->where('id', $org)->value('experience'));
        $this->assertNull($user->refresh()->registration_workspace_id);
        $this->getJson('/professional')->assertJsonPath('subscription.state', 'pending activation');
        $partner = app(Workspaces::class)->create($user->id, 'Partner');
        $this->postJson('/workspace/select', ['org_id' => $partner])->assertOk();
        $this->get('/portal/enter')->assertRedirect('/partner/leads');
        $this->postJson('/workspace/select', ['org_id' => $org])->assertOk();
        $this->get('/portal/enter')->assertRedirect('/professional');
    }

    public function test_expiry_blocks_handoff_public_submissions_workers_and_renewal_does_not_replay(): void
    {
        [$user,$org] = $this->professional();
        $this->activate($org);
        $this->actingAs($user);
        $lead = $this->postJson('/pipeline', $this->lead())->assertCreated()->json('id');
        $draft = $this->postJson('/automation/drafts', ['pipeline_entry_id' => $lead, 'recipient' => 'client@example.test', 'subject' => 'Client follow-up', 'body' => 'Private manual content'])->assertCreated()->json('id');
        $this->postJson('/automation/drafts/'.$draft.'/approve', ['version' => 1])->assertOk();
        $invitation = $this->postJson('/onboarding-requests', ['pipeline_entry_id' => $lead, 'label' => 'Client intake'])->assertCreated()->json();
        $this->postJson('/onboarding/session', ['token' => explode('token=', $invitation['url'])[1]])->assertOk();
        $this->putJson('/automation/preferences', ['version' => 0, 'timezone' => 'UTC', 'reminders' => true, 'weekly_digest' => true, 'follow_up_drafts' => true])->assertOk();
        $schedule = $this->postJson('/automation/schedules', ['name' => 'Weekly review', 'kind' => 'weekly_digest', 'weekday' => 1, 'local_time' => '09:00', 'consent' => true])->assertCreated()->json('id');
        $this->control->table('business_schedules')->where('id', $schedule)->update(['next_due_at' => now()->subMinute()]);
        app(BusinessSchedules::class)->materialize($user->id, $org, $schedule);
        $run = $this->control->table('schedule_runs')->where('schedule_id', $schedule)->value('id');
        $this->control->table('pilot_assignments')->where('org_id', $org)->update(['ends_at' => now()->subMinute()]);
        $this->getJson('/automation/drafts/'.$draft.'/handoff?version=2')->assertForbidden();
        $this->postJson('/automation/drafts/'.$draft.'/approve', ['version' => 2])->assertForbidden();
        $this->patchJson('/pipeline/'.$lead, [...$this->lead(), 'version' => 1])->assertUnprocessable();
        $this->patchJson('/onboarding/form/'.$invitation['id'], ['version' => 1, 'business_name' => 'Individual', 'contact_name' => 'Client', 'business_email' => 'client@example.test', 'website' => '', 'brief' => ''])->assertForbidden();
        app(ScheduledNotifications::class)->consume($user->id, $org, $schedule, $run);
        $this->assertSame('cancelled', $this->control->table('schedule_runs')->where('id', $run)->value('state'));
        $this->assertSame(0, $this->control->table('notification_outbox')->where('org_id', $org)->count());
        // Access revocation still works, retaining the saved content.
        $this->postJson('/onboarding-requests/'.$invitation['id'].'/revoke', ['version' => 1])->assertOk();
        $this->activate($org, 1);
        $this->assertFalse($this->control->table('business_schedules')->where('id', $schedule)->value('enabled'));
        $this->assertSame('cancelled', $this->control->table('follow_up_drafts')->where('id', $draft)->value('state'));
        $this->assertSame('Private manual content', $this->control->table('follow_up_drafts')->where('id', $draft)->value('body'));
        $this->actingAs($user)->getJson('/professional/exports/leads')->assertOk()->assertDontSee('Private manual content');
    }

    public function test_concurrent_activation_serializes_and_failed_assignment_audit_rolls_back(): void
    {
        [, $org] = $this->professional();
        $operator = $this->operator();
        $process = null;
        try {
            app(TenantContext::class)->run($operator->id, null, function () use ($org, $operator, &$process) {
                DB::select("SELECT set_config('app.admin_version','1',true)");
                DB::select('SELECT app_platform_assign(?::uuid,0,?,?::jsonb,?::timestamptz,?::timestamptz,?,?::uuid,?::uuid)', [$org, 'Race '.Str::uuid(), json_encode(array_fill_keys(self::RESOURCES, 10)), now()->subHour(), now()->addDay(), 'Concurrent assignment', (string) Str::uuid(), (string) Str::uuid()]);
                $process = new Process([PHP_BINARY, 'tests/Fixtures/professional-assignment.php', (string) $operator->id, $org], base_path(), ['APP_ENV' => 'testing', 'TEST_MIGRATION_DB_PASSWORD' => false]);
                $process->start();
                $this->eventually(fn () => str_contains($process->getOutput(), "\n"), 'Probe connected');
                $this->assertBlocked($process);
            });
            $this->assertSame(['state' => '40001'], $this->probeResult($process));
            $this->assertSame(1, $this->control->table('pilot_assignments')->where('org_id', $org)->count());
            $this->assertSame(1, $this->control->table('platform_audit')->where('target', $org)->count());
        } finally {
            $process?->stop(0);
        }
        $other = app(Workspaces::class)->create($operator->id, 'Atomic professional', experience: 'professional');
        try {
            app(TenantContext::class)->run($operator->id, null, function () use ($other) {
                DB::select("SELECT set_config('app.admin_version','1',true)");
                DB::select('SELECT app_platform_assign(?::uuid,0,?,?::jsonb,?::timestamptz,?::timestamptz,?,?::uuid,?::uuid)', [$other, 'Atomic '.Str::uuid(), json_encode(array_fill_keys(self::RESOURCES, 10)), now()->subHour(), now()->addDay(), 'Atomic rollback', (string) Str::uuid(), (string) Str::uuid()]);
                throw new \RuntimeException('Rollback synthetic assignment');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Rollback synthetic assignment', $error->getMessage());
        }
        $this->assertSame(0, $this->control->table('pilot_assignments')->where('org_id', $other)->count());
        $this->assertSame(0, $this->control->table('platform_audit')->where('target', $other)->count());
    }

    public function test_offline_bootstrap_recovery_last_owner_and_runtime_denial(): void
    {
        $owner = User::factory()->create();
        $arguments = ['user' => (string) $owner->id, '--permission' => ['platform.owner'], '--operator' => 'Synthetic offline operator', '--reason' => 'Disposable bootstrap rehearsal'];
        $this->assertSame(1, Artisan::call('platform:grant', $arguments));
        $this->control->beginTransaction();
        DB::setDefaultConnection('acceptance_control');
        try {
            // Isolate the last-owner scenario without changing prior fixtures after rollback.
            DB::table('platform_grants')->update(['revoked_at' => now()]);
            $this->assertSame(0, Artisan::call('platform:grant', $arguments));
            $this->assertSame(1, DB::table('platform_grants')->where('user_id', $owner->id)->value('version'));
            DB::table('users')->where('id', $owner->id)->update(['admin_totp_confirmed_at' => now(), 'admin_totp_secret' => encrypt('JBSWY3DPEHPK3PXP')]);
            try {
                Artisan::call('platform:grant', ['user' => (string) $owner->id, '--revoke' => true, '--operator' => 'Synthetic operator', '--reason' => 'Last-owner denial']);
                $this->fail('Last owner was revoked');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertSame(0, Artisan::call('platform:grant', ['user' => (string) $owner->id, '--recover' => true, '--operator' => 'Synthetic operator', '--reason' => 'Controlled MFA recovery']));
            $this->assertNull(DB::table('users')->where('id', $owner->id)->value('admin_totp_secret'));
            $this->assertSame(2, DB::table('platform_grants')->where('user_id', $owner->id)->value('version'));
            $this->assertSame(2, DB::table('platform_audit')->where('target', (string) $owner->id)->count());
        } finally {
            $this->control->rollBack();
            DB::setDefaultConnection('pgsql');
        }
    }

    public function test_runtime_privilege_denial_and_optional_brand_relationship_scope(): void
    {
        [$user,$org] = $this->professional();
        app(TenantContext::class)->run($user->id, $org, function () {
            foreach ([fn () => DB::select('SELECT app_platform_directory(?,1)', ['']), fn () => DB::table('platform_grants')->insert(['user_id' => 999999999, 'permissions' => '{platform.owner}'])] as $attack) {
                try {
                    DB::transaction($attack);
                    $this->fail('Runtime privilege escalation succeeded');
                } catch (\PDOException $error) {
                    $this->assertSame('42501', $error->errorInfo[0]);
                }
            }
        });
        $brand = User::factory()->create();
        $brandOrg = app(Workspaces::class)->create($brand->id, 'Optional brand', 'brand');
        $invite = $this->actingAs($brand)->withSession(['active_org_id' => $brandOrg])->postJson('/partner-invitations', ['email' => $user->email])->assertCreated()->json();
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $this->post('/partnerships/prepare', ['token' => explode('#', $invite['url'])[1]])->assertRedirect('/portal/enter');
        $this->get('/portal/enter')->assertRedirect('/partnerships/accept');
        $this->postJson('/partnerships/accept', ['token' => explode('#', $invite['url'])[1], 'consent' => true])->assertOk();
        $this->getJson('/professional/leads')->assertJsonCount(1, 'relationships');
        $this->activate($org);
        $this->actingAs($user)->postJson('/pipeline', $this->lead('Private professional client'))->assertCreated();
        $this->actingAs($brand)->withSession(['active_org_id' => $brandOrg])->getJson('/pipeline')->assertDontSee('Private professional client');
        $this->getJson('/professional/exports/leads')->assertNotFound();
    }

    public function test_send_reservation_rechecks_expiry_and_totp_matches_rfc_vector(): void
    {
        $this->assertSame('287082', app(PlatformAdministration::class)->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1));
        [$user,$org] = $this->professional();
        $this->activate($org);
        $this->actingAs($user);
        $lead = $this->postJson('/pipeline', $this->lead())->assertCreated()->json('id');
        $this->postJson('/onboarding-requests', ['pipeline_entry_id' => $lead, 'label' => 'Client intake', 'send_email' => true, 'recipient_email' => 'client@example.test'])->assertCreated();
        $notice = $this->control->table('notification_outbox')->where('org_id', $org)->first();
        $this->assertNotNull($notice);
        $this->control->table('pilot_assignments')->where('org_id', $org)->update(['ends_at' => now()->subSecond()]);
        app(NotificationOutbox::class)->deliver($user->id, $org, $notice->id);
        $this->assertSame('cancelled', $this->control->table('notification_outbox')->where('id', $notice->id)->value('state'));
        $this->assertNull($this->control->table('notification_outbox')->where('id', $notice->id)->value('encrypted_payload'));
    }

    public function test_expired_finance_exports_preserve_exact_line_items_and_allocation_history(): void
    {
        [$user,$org] = $this->professional();
        $this->activate($org);
        $this->actingAs($user);
        $invoice = $this->postJson('/finance/invoices', ['recipient_name' => 'Individual Client', 'currency' => 'USD', 'command_id' => (string) Str::uuid(), 'lines' => [['description' => 'Consulting line', 'quantity' => 1, 'unit_price_minor' => '9007199254740993']]])->assertCreated()->json('id');
        $payment = $this->postJson('/finance/payments', ['currency' => 'USD', 'amount_minor' => '100', 'received_on' => now()->toDateString(), 'evidence_reference' => 'Synthetic export evidence', 'command_id' => (string) Str::uuid()])->assertCreated()->json('id');
        $allocation = $this->postJson('/finance/payments/'.$payment.'/allocate', ['version' => 1, 'invoice_id' => $invoice, 'invoice_version' => 1, 'amount_minor' => '10', 'command_id' => (string) Str::uuid()])->assertOk()->json('id');
        $this->postJson('/finance/allocations/'.$allocation.'/reverse', ['reason' => 'Synthetic correction', 'command_id' => (string) Str::uuid()])->assertOk();
        $this->activate($org, 1, 'expired');
        $this->actingAs($user);
        $this->getJson('/professional/exports/invoices')->assertOk()->assertSee('Consulting line')->assertSee('9007199254740993');
        $this->getJson('/professional/exports/payments')->assertOk()->assertSee('Synthetic correction')->assertSee($allocation);
    }
}
