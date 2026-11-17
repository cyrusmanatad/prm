<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\PlatformAdministration;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformConsoleTest extends AcceptanceCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private const PASSWORD = 'SyntheticPassword123!';

    private const READS = ['/admin/accounts', '/admin/workspaces', '/admin/plans', '/admin/assignments', '/admin/notifications', '/admin/operations', '/admin/audit', '/admin/operators'];

    private function operator(string $permissions): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $this->control->table('platform_grants')->insert(['user_id' => $user->id, 'permissions' => $permissions]);
        $this->control->table('users')->where('id', $user->id)->update(['admin_totp_secret' => encrypt(self::SECRET), 'admin_totp_confirmed_at' => now()]);

        return $user->refresh();
    }

    private function verified(User $user, int $version = 1): static
    {
        return $this->actingAs($user)->withSession(['platform_mfa' => ['user' => $user->id, 'version' => $version, 'until' => time() + 900]]);
    }

    private function code(int $offset = 0): string
    {
        return app(PlatformAdministration::class)->code(self::SECRET, intdiv(time(), 30) + $offset);
    }

    private function grant(User $target, array $overrides = []): array
    {
        return [...['user' => $target->id, 'expected_version' => 0, 'revoke' => false, 'capabilities' => ['directory.read'], 'reason' => 'Synthetic reviewer', 'password' => self::PASSWORD, 'code' => $this->code()], ...$overrides];
    }

    public function test_reads_require_a_grant_step_up_current_version_and_the_matching_capability(): void
    {
        $outsider = User::factory()->create();
        foreach ([...self::READS, '/admin', '/admin/support-access', '/admin/cleanup'] as $path) {
            $this->actingAs($outsider)->getJson($path)->assertForbidden();
            $this->actingAs($outsider)->get($path)->assertForbidden();
        }
        $operator = $this->operator('{directory.read}');
        foreach (self::READS as $path) {
            // No step-up: JSON is denied; a page visit returns to the challenge.
            $this->actingAs($operator)->getJson($path)->assertForbidden();
            $this->actingAs($operator)->get($path)->assertRedirect('/admin/security');
            $this->withSession(['platform_mfa' => ['user' => $operator->id, 'version' => 1, 'until' => time() - 1]])->getJson($path)->assertForbidden();
            $this->withSession(['platform_mfa' => ['user' => $operator->id, 'version' => 2, 'until' => time() + 900]])->getJson($path)->assertForbidden();
            $this->withSession(['platform_mfa' => ['user' => $outsider->id, 'version' => 1, 'until' => time() + 900]])->getJson($path)->assertForbidden();
        }
        $this->verified($operator);
        foreach (self::READS as $path) {
            $response = $this->getJson($path);
            in_array($path, ['/admin/accounts', '/admin/workspaces'], true) ? $response->assertOk() : $response->assertForbidden();
        }
        $this->getJson('/admin/accounts/'.$outsider->id)->assertOk()->assertJsonPath('activity', null);
        $this->getJson('/admin/accounts/999999999')->assertNotFound();
        $this->getJson('/admin/workspaces/'.Str::uuid())->assertNotFound();
        $this->getJson('/admin/assignments/create?workspace='.Str::uuid())->assertForbidden();
        $this->get('/admin')->assertOk();
        // Support access and cleanup are separate capabilities, not implied by directory access.
        $this->get('/admin/support-access')->assertForbidden();
        $this->get('/admin/cleanup')->assertForbidden();
        // A revoked grant ends access even while the old proof is unexpired.
        $this->control->table('platform_grants')->where('user_id', $operator->id)->update(['revoked_at' => now(), 'version' => 2]);
        foreach ([...self::READS, '/admin', '/admin/cleanup'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->assertGreaterThan(0, $this->control->table('platform_audit')->where('actor_id', $operator->id)->where('event', 'request.denied')->count());
    }

    public function test_step_up_returns_to_the_requested_administration_page_only(): void
    {
        $operator = $this->operator('{directory.read}');
        $this->actingAs($operator)->get('/admin/workspaces?kind=brand')->assertRedirect('/admin/security');
        $this->post('/admin/security/verify', ['password' => self::PASSWORD, 'code' => $this->code()])->assertRedirect('/admin/workspaces?kind=brand');
        $this->withSession(['platform_intended' => 'https://example.test/admin'])->post('/admin/security/verify', ['password' => self::PASSWORD, 'code' => $this->code(1)])->assertRedirect('/admin');
    }

    public function test_projections_return_metadata_and_counts_without_private_workspace_content(): void
    {
        $owner = User::factory()->create(['name' => 'Jordan Vale Synthetic']);
        $name = 'Cedar Studio '.Str::uuid();
        $org = app(Workspaces::class)->create($owner->id, $name);
        $this->actingAs($owner)->withSession(['active_org_id' => $org])->postJson('/pipeline', ['brand_name' => 'PRIVATE-BRAND-MARKER', 'contact_name' => 'PRIVATE-CONTACT-MARKER',
            'contact_email' => 'private-marker@example.test', 'notes' => 'PRIVATE-NOTE-MARKER', 'value_minor' => '987654', 'currency' => 'USD'])->assertCreated();
        $operator = $this->operator('{directory.read,plans.assign,notifications.read,operations.read,audit.read}');
        $this->verified($operator);
        $workspace = $this->getJson('/admin/workspaces/'.$org)->assertOk()->assertJsonPath('workspace.name', $name)->assertJsonPath('entitlement.state', 'unassigned-development')
            ->assertJsonPath('entitlement.usage.0.resource', 'pipeline_entries')->assertJsonPath('entitlement.usage.0.used', 1)->assertJsonPath('entitlement.usage.0.limit', null)
            ->assertJsonPath('members.0.role', 'owner')->assertJsonMissingPath('members.0.password');
        $account = $this->getJson('/admin/accounts/'.$owner->id)->assertOk()->assertJsonPath('memberships.0.org_id', $org)->assertJsonPath('security.mfa_enrolled', false)
            ->assertJsonMissingPath('account.password')->assertJsonMissingPath('account.remember_token')->assertJsonMissingPath('account.admin_totp_secret')->assertJsonMissingPath('security.session_ids');
        $pages = [$workspace, $account, $this->getJson('/admin/workspaces?q='.urlencode($name))->assertOk()->assertJsonPath('workspaces.total', 1)->assertJsonPath('workspaces.rows.0.owners.0', 'Jordan Vale Synthetic'),
            $this->getJson('/admin/accounts?q='.urlencode($owner->email))->assertOk()->assertJsonPath('accounts.total', 1), $this->getJson('/admin/audit')->assertOk(), $this->getJson('/admin/operations')->assertOk(),
            $this->getJson('/admin/notifications')->assertOk(), $this->getJson('/admin/assignments')->assertOk(), $this->getJson('/admin/plans')->assertOk(), $this->get('/admin')->assertOk()];
        foreach ($pages as $page) {
            foreach (['PRIVATE-BRAND-MARKER', 'PRIVATE-CONTACT-MARKER', 'PRIVATE-NOTE-MARKER', 'private-marker@example.test', '987654', self::SECRET] as $marker) {
                $page->assertDontSee($marker);
            }
        }
        // Wildcards in a search term are literal, not patterns.
        $this->getJson('/admin/workspaces?q='.urlencode('%'))->assertOk()->assertJsonPath('workspaces.total', 0);
        $this->getJson('/admin/accounts?verified=maybe')->assertUnprocessable();
    }

    public function test_runtime_role_cannot_call_gated_projections_or_touch_their_tables_directly(): void
    {
        $operator = $this->operator('{platform.owner}');
        $grants = $this->control->table('platform_grants')->count();
        $attempts = [
            fn () => DB::select("SELECT app_platform_accounts('{}'::jsonb,1)"),
            fn () => DB::select('SELECT app_platform_operators()'),
            fn () => DB::select('SELECT app_platform_overview()'),
            fn () => DB::select("SELECT app_platform_grant_save(?,0,'{platform.owner}'::text[],false,'escalate')", [User::factory()->create()->id]),
            // Helpers that read across tenants are not executable by the runtime role at all.
            fn () => DB::select('SELECT app_platform_activity(?,NULL)', [(string) $operator->id]),
            fn () => DB::select('SELECT app_entitlement_state(?::uuid)', [(string) Str::uuid()]),
            fn () => DB::statement("INSERT INTO platform_observations(source,observed_at) VALUES('scheduler',now())"),
            fn () => DB::statement("SELECT app_record_observation('scanner')"),
        ];
        foreach ($attempts as $index => $attempt) {
            try {
                // A valid grant without the request's verified step-up version must not be enough.
                app(TenantContext::class)->run($operator->id, null, $attempt);
                $this->fail('Attempt '.$index.' was not denied.');
            } catch (QueryException $error) {
                $this->assertContains($error->errorInfo[0], ['42501', '22000'], 'Attempt '.$index);
            }
        }
        $this->assertSame(0, app(TenantContext::class)->run($operator->id, null, fn () => DB::table('platform_observations')->count()));
        $this->assertSame($grants, $this->control->table('platform_grants')->count());
    }

    public function test_operator_grants_need_owner_fresh_proof_current_version_and_cannot_target_self(): void
    {
        $owner = $this->operator('{platform.owner}');
        $scoped = $this->operator('{directory.read,audit.read}');
        $target = User::factory()->create();
        $unverified = User::factory()->unverified()->create();
        $this->control->table('sessions')->insert(['id' => 'synthetic-target-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);

        $this->verified($scoped)->postJson('/admin/operators', $this->grant($target))->assertForbidden();
        // Validation failures use a second owner so the per-operator rate limit is not what rejects them.
        $this->verified($this->operator('{platform.owner}'));
        $this->postJson('/admin/operators', $this->grant($target, ['code' => '']))->assertUnprocessable();
        $this->postJson('/admin/operators', $this->grant($target, ['password' => 'wrong-password']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/admin/operators', $this->grant($target, ['code' => '000000']))->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/admin/operators', $this->grant($target, ['capabilities' => ['tenant.superuser']]))->assertUnprocessable();
        $this->postJson('/admin/operators', $this->grant($target, ['reason' => '   ']))->assertUnprocessable();
        $this->assertNull($this->control->table('platform_grants')->where('user_id', $target->id)->first());
        $this->assertSame(1, $this->control->table('sessions')->where('user_id', $target->id)->count());

        // Rejections must roll back the consumed authenticator counter with the rest of the transaction.
        $this->verified($owner);
        $this->postJson('/admin/operators', $this->grant($unverified, ['code' => $this->code(-1)]))->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->postJson('/admin/operators', $this->grant($owner, ['code' => $this->code(-1)]))->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->postJson('/admin/operators', $this->grant($target, ['expected_version' => 3, 'code' => $this->code(-1)]))->assertConflict();

        $data = $this->grant($target, ['capabilities' => ['directory.read', 'audit.read'], 'code' => $this->code(-1)]);
        $this->postJson('/admin/operators', $data)->assertOk();
        $this->postJson('/admin/operators', $data)->assertUnprocessable()->assertJsonValidationErrors('code');
        $grant = $this->control->table('platform_grants')->where('user_id', $target->id)->first();
        $this->assertSame('{audit.read,directory.read}', $grant->permissions);
        $this->assertSame(1, $grant->version);
        $this->assertSame(0, $this->control->table('sessions')->where('user_id', $target->id)->count());
        $this->assertSame(1, $this->control->table('platform_audit')->where(['actor_id' => $owner->id, 'event' => 'grant.assigned', 'target' => (string) $target->id, 'reason' => 'Synthetic reviewer'])->count());
        $this->getJson('/admin/operators')->assertOk()->assertJsonPath('operators.0.self', fn ($value) => is_bool($value))->assertJsonMissingPath('operators.0.admin_totp_secret');

        // The new operator has no authenticator yet, so the grant is unusable until they enroll.
        $this->verified($target)->getJson('/admin/accounts')->assertForbidden();

        $this->verified($owner)->postJson('/admin/operators', $this->grant($target, ['expected_version' => 0, 'code' => $this->code()]))->assertConflict();
        $this->postJson('/admin/operators', $this->grant($target, ['expected_version' => 1, 'revoke' => true, 'capabilities' => [], 'reason' => 'Review complete', 'code' => $this->code()]))->assertOk();
        $revoked = $this->control->table('platform_grants')->where('user_id', $target->id)->first();
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame(2, $revoked->version);
        $this->assertSame(1, $this->control->table('platform_audit')->where(['event' => 'grant.revoked', 'target' => (string) $target->id, 'reason' => 'Review complete'])->count());
        $this->postJson('/admin/operators', $this->grant($target, ['expected_version' => 2, 'revoke' => true, 'capabilities' => [], 'code' => $this->code(1)]))->assertConflict();
        // The owner's own grant is untouched throughout.
        $this->assertSame(1, $this->control->table('platform_grants')->where('user_id', $owner->id)->whereNull('revoked_at')->value('version'));
    }

    public function test_diagnostics_are_sanitized_and_unobserved_health_is_unknown(): void
    {
        $before = ['total' => $this->control->table('failed_jobs')->count(), 'recent' => $this->control->table('failed_jobs')->where('failed_at', '>', now()->subDay())->count()];
        $jobs = ['named' => (string) Str::uuid(), 'malformed' => (string) Str::uuid(), 'foreign' => (string) Str::uuid(), 'old' => (string) Str::uuid()];
        // Dated just ahead of other rows so the newest-25 projection is certain to include them.
        $this->control->table('failed_jobs')->insert([
            ['uuid' => $jobs['named'], 'connection' => 'redis', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\SendNotification', 'data' => 'PRIVATE-PAYLOAD-MARKER']), 'exception' => 'RuntimeException: PRIVATE-EXCEPTION-MARKER', 'failed_at' => now()->addMinutes(3)],
            ['uuid' => $jobs['malformed'], 'connection' => 'redis', 'queue' => 'default', 'payload' => 'not json PRIVATE-PAYLOAD-MARKER', 'exception' => 'PRIVATE-EXCEPTION-MARKER with no class', 'failed_at' => now()->addMinutes(2)],
            ['uuid' => $jobs['foreign'], 'connection' => 'redis', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'Vendor\\Leaky PRIVATE-PAYLOAD-MARKER']), 'exception' => 'Illuminate\\Database\\QueryException: PRIVATE-EXCEPTION-MARKER', 'failed_at' => now()->addMinute()],
            ['uuid' => $jobs['old'], 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'LogicException: PRIVATE-EXCEPTION-MARKER', 'failed_at' => now()->subDays(3)],
        ]);
        $operator = $this->operator('{operations.read}');
        $this->control->table('platform_observations')->delete();
        $response = $this->verified($operator)->getJson('/admin/operations')->assertOk()->assertJsonPath('scheduler', null)
            ->assertJsonPath('failed.total', $before['total'] + 4)->assertJsonPath('failed.last_24_hours', $before['recent'] + 3)
            ->assertJsonMissingPath('failed.rows.0.payload')->assertJsonMissingPath('failed.rows.0.exception');
        $rows = collect($response->json('failed.rows'))->keyBy('reference');
        $this->assertSame(['App\\Jobs\\SendNotification', 'RuntimeException'], [$rows[$jobs['named']]['job'], $rows[$jobs['named']]['category']]);
        $this->assertSame([null, null], [$rows[$jobs['malformed']]['job'], $rows[$jobs['malformed']]['category']]);
        $this->assertSame([null, 'Illuminate\\Database\\QueryException'], [$rows[$jobs['foreign']]['job'], $rows[$jobs['foreign']]['category']]);
        $response->assertDontSee('PRIVATE-PAYLOAD-MARKER')->assertDontSee('PRIVATE-EXCEPTION-MARKER');
        DB::select("SELECT app_record_observation('scheduler')");
        $this->getJson('/admin/operations')->assertOk()->assertJsonPath('scheduler.age_seconds', fn ($age) => is_int($age) && $age >= 0 && $age < 60);
        $this->getJson('/admin/accounts')->assertForbidden();
    }

    public function test_audit_review_lists_actions_and_denials_with_recorded_fields_only(): void
    {
        $owner = User::factory()->create();
        $org = app(Workspaces::class)->create($owner->id, 'Audit target synthetic');
        $reviewer = $this->operator('{audit.read}');
        $this->verified($reviewer)->getJson('/admin/operators')->assertForbidden();
        $this->control->table('pilot_admin_events')->insert(['id' => $pilot = (string) Str::uuid(), 'operator' => 'local-operator', 'reason' => 'Local pilot reason', 'event' => 'plan.created', 'subject_id' => $subject = (string) Str::uuid(), 'org_id' => $org]);
        $mine = '&q='.urlencode($reviewer->email);
        $denied = $this->getJson('/admin/audit?outcome=denied'.$mine)->assertOk()->assertJsonPath('events.total', 1)->assertJsonPath('events.rows.0.event', 'request.denied')
            ->assertJsonPath('events.rows.0.actor_id', $reviewer->id)->assertJsonMissingPath('events.rows.0.reason');
        $event = $denied->json('events.rows.0.id');
        $this->getJson('/admin/audit/platform/'.$event)->assertOk()->assertJsonPath('event.outcome', 'denied')->assertJsonPath('event.target', 'admin/operators');
        $this->getJson('/admin/audit?source=local-pilot&q='.$subject)->assertOk()->assertJsonPath('events.total', 1)->assertJsonPath('events.rows.0.outcome', null)->assertJsonPath('events.rows.0.workspace', 'Audit target synthetic');
        $this->getJson('/admin/audit?source=platform&q='.$subject)->assertOk()->assertJsonPath('events.total', 0);
        $this->getJson('/admin/audit/local-pilot/'.$pilot)->assertOk()->assertJsonPath('event.reason', 'Local pilot reason')->assertJsonPath('event.actor_id', null);
        $this->getJson('/admin/audit?to='.now()->format('Y-m-d').$mine)->assertOk()->assertJsonPath('events.total', 1);
        // A failed lookup is itself recorded as a denied request.
        $this->getJson('/admin/audit/platform/999999999')->assertNotFound();
        $this->getJson('/admin/audit?outcome=recorded'.$mine)->assertOk()->assertJsonPath('events.total', 0);
        $this->getJson('/admin/audit?from='.now()->addDay()->format('Y-m-d').$mine)->assertOk()->assertJsonPath('events.total', 0);
        $this->getJson('/admin/audit?from=2026-13-40')->assertUnprocessable();
        // The audit trail has no write path through the runtime role.
        try {
            app(TenantContext::class)->run($reviewer->id, null, fn () => DB::table('platform_audit')->delete());
            $this->assertSame(1, $this->control->table('platform_audit')->where('id', $event)->count());
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->errorInfo[0]);
        }
        $this->assertSame(1, $this->control->table('platform_audit')->where('id', $event)->count());
    }

    public function test_revocation_waits_for_the_target_operators_in_flight_request(): void
    {
        $owner = $this->operator('{platform.owner}');
        $target = $this->operator('{directory.read}');
        // The target's own privileged request holds a share lock on their grant until it finishes.
        $this->control->beginTransaction();
        $this->control->select('SELECT 1 FROM platform_grants WHERE user_id=? FOR SHARE', [$target->id]);
        DB::statement("SET lock_timeout = '1500ms'");
        try {
            $this->verified($owner)->postJson('/admin/operators', $this->grant($target, ['expected_version' => 1, 'revoke' => true, 'capabilities' => []]))->assertServerError();
        } finally {
            DB::statement('RESET lock_timeout');
            $this->control->rollBack();
        }
        $this->assertNull($this->control->table('platform_grants')->where('user_id', $target->id)->value('revoked_at'));
        $this->assertSame(0, $this->control->table('platform_audit')->where(['event' => 'grant.revoked', 'target' => (string) $target->id])->count());
        // Once that request has finished the same revocation succeeds, with the rolled-back code still unused.
        $this->postJson('/admin/operators', $this->grant($target, ['expected_version' => 1, 'revoke' => true, 'capabilities' => []]))->assertOk();
        $this->assertNotNull($this->control->table('platform_grants')->where('user_id', $target->id)->value('revoked_at'));
    }
}
