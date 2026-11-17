<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\Authenticator;
use App\Services\Workspaces;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Workspace-owner MFA (M1-04) against the real runtime role. Decisions D18, D34, D35. */
class OwnerMfaTest extends AcceptanceCase
{
    private const PASSWORD = 'SyntheticPassword123!';

    private const BLOCKED = 'Verify your authenticator to continue.';

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.owner_mfa' => true]);
    }

    private function owner(): array
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);

        return [$user, app(Workspaces::class)->create($user->id, 'Owner MFA '.Str::uuid())];
    }

    private function code(string $secret, int $offset = 0): string
    {
        return app(Authenticator::class)->code($secret, intdiv(time(), 30) + $offset);
    }

    /** Sets up an authenticator through the application and returns the secret and the recovery codes. */
    private function enroll(User $user): array
    {
        $secret = $this->actingAs($user)->postJson('/account/authenticator/enroll', ['password' => self::PASSWORD])->assertOk()->json('secret');
        $codes = $this->postJson('/account/authenticator/verify', ['code' => $this->code($secret)])->assertOk()->json('recoveryCodes');
        // Later checks in the same thirty seconds need an unused step.
        $this->control->table('users')->where('id', $user->id)->update(['admin_totp_counter' => null]);

        return [$secret, $codes];
    }

    /** A new sign-in: same account, no proof in the session. */
    private function fresh(User $user): static
    {
        $this->flushSession();

        return $this->actingAs($user->refresh());
    }

    public function test_an_owner_reaches_only_the_authenticator_page_until_enrolled_and_verified(): void
    {
        [$owner, $org] = $this->owner();
        $this->actingAs($owner)->withSession(['active_org_id' => $org]);
        $this->getJson('/pipeline')->assertForbidden()->assertJsonPath('message', self::BLOCKED);
        $this->getJson('/workspaces')->assertForbidden();
        $this->getJson('/account')->assertForbidden();
        $this->postJson('/workspaces', ['name' => 'Second'])->assertForbidden();
        $this->postJson('/automation/schedules', ['kind' => 'weekly_digest'])->assertForbidden();
        $this->deleteJson('/sessions', ['password' => self::PASSWORD])->assertForbidden();
        $this->get('/workspaces')->assertRedirect('/account/authenticator');
        $this->get('/admin')->assertRedirect('/account/authenticator');
        $this->get('/join')->assertRedirect('/account/authenticator');
        $this->post('/pipeline', ['title' => 'Blocked'])->assertRedirect('/account/authenticator');
        $this->assertSame(1, $this->control->table('organizations')->join('memberships', 'memberships.org_id', '=', 'organizations.id')->where('memberships.user_id', $owner->id)->count());
        $this->getJson('/account/authenticator')->assertOk()->assertJson(['enrolled' => false, 'verified' => false, 'required' => true, 'recoveryRemaining' => null]);

        // Setup needs the password; a wrong code confirms nothing.
        $this->postJson('/account/authenticator/enroll', ['password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $secret = $this->postJson('/account/authenticator/enroll', ['password' => self::PASSWORD])->assertOk()->json('secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->postJson('/account/authenticator/verify', ['code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertNull($this->control->table('users')->where('id', $owner->id)->value('admin_totp_confirmed_at'));
        $this->getJson('/pipeline')->assertForbidden();

        $codes = $this->postJson('/account/authenticator/verify', ['code' => $this->code($secret)])->assertOk()->assertJsonPath('verified', true)->json('recoveryCodes');
        $this->assertCount(10, array_unique($codes));
        $this->assertSame(1, $this->control->table('account_security_events')->where(['user_id' => $owner->id, 'event' => 'authenticator.enrolled'])->count());
        $stored = $this->control->table('users')->where('id', $owner->id)->first();
        $this->assertNotSame($secret, $stored->admin_totp_secret);
        $this->assertSame($secret, decrypt($stored->admin_totp_secret));
        $this->assertSame(0, $this->control->table('account_recovery_codes')->where('user_id', $owner->id)->whereIn('code_hash', $codes)->count());
        // A real request loads the account again; the test's instance predates enrollment.
        $this->actingAs($owner->refresh())->getJson('/pipeline')->assertOk();
        $this->getJson('/account/authenticator')->assertOk()->assertJson(['enrolled' => true, 'verified' => true, 'recoveryRemaining' => 10]);
    }

    public function test_every_sign_in_entrance_registration_and_a_session_that_becomes_an_owner_are_checked(): void
    {
        [$owner] = $this->owner();
        [$secret] = $this->enroll($owner);
        foreach (['/login', '/brand/login', '/partner/login', '/professional/login'] as $entrance) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $this->postJson($entrance, ['email' => $owner->email, 'password' => self::PASSWORD])->assertOk();
            $this->assertAuthenticatedAs($owner);
            $this->getJson('/workspaces')->assertForbidden()->assertJsonPath('message', self::BLOCKED);
            $this->get('/portal/enter')->assertRedirect('/account/authenticator');
        }
        // The check returns to the interrupted page, and the same code cannot be used twice.
        $this->post('/account/authenticator/verify', ['code' => $this->code($secret)])->assertRedirect('/portal/enter');
        $this->getJson('/workspaces')->assertOk();
        $this->fresh($owner)->postJson('/account/authenticator/verify', ['code' => $this->code($secret)])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->getJson('/workspaces')->assertForbidden();
        $this->postJson('/account/authenticator/verify', ['code' => $this->code($secret, 1)])->assertOk();
        $this->getJson('/workspaces')->assertOk();

        // Registration signs in directly. The account is free until it creates a workspace in that session.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $email = 'owner-mfa-'.Str::uuid().'@example.test';
        $this->postJson('/register', ['name' => 'New Owner', 'email' => $email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertCreated();
        $this->control->table('users')->where('email', $email)->update(['email_verified_at' => now()]);
        $this->actingAs(User::where('email', $email)->firstOrFail());
        $this->getJson('/workspaces')->assertOk();
        $this->postJson('/workspaces', ['name' => 'First workspace'])->assertCreated();
        $this->getJson('/workspaces')->assertForbidden()->assertJsonPath('message', self::BLOCKED);
        $this->getJson('/pipeline')->assertForbidden();
    }

    public function test_other_roles_are_unaffected_and_an_enrolled_account_is_still_asked_after_losing_ownership(): void
    {
        [$owner, $org] = $this->owner();
        foreach (['admin', 'manager', 'member', 'viewer'] as $role) {
            $peer = User::factory()->create();
            $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $peer->id, 'role' => $role]);
            $this->fresh($peer)->withSession(['active_org_id' => $org])->getJson('/pipeline')->assertOk();
        }
        $this->fresh(User::factory()->create())->getJson('/workspaces')->assertOk();

        $this->flushSession();
        $this->enroll($owner);
        $this->control->table('memberships')->where(['org_id' => $org, 'user_id' => $owner->id])->update(['role' => 'member']);
        $this->fresh($owner)->getJson('/workspaces')->assertForbidden()->assertJsonPath('message', self::BLOCKED);
        $this->getJson('/account/authenticator')->assertOk()->assertJson(['enrolled' => true, 'verified' => false, 'required' => true]);
    }

    public function test_a_recovery_code_signs_in_once_and_repeated_failures_lock_the_check(): void
    {
        [$owner] = $this->owner();
        [$secret, $codes] = $this->enroll($owner);
        [$other] = $this->owner();
        [, $foreign] = $this->enroll($other);

        $this->fresh($owner)->postJson('/account/authenticator/recover', ['recovery_code' => $foreign[0]])->assertUnprocessable()->assertJsonValidationErrors('recovery_code');
        $this->getJson('/workspaces')->assertForbidden();
        $this->postJson('/account/authenticator/recover', ['recovery_code' => strtolower(str_replace('-', ' ', $codes[0]))])->assertOk();
        $this->getJson('/workspaces')->assertOk();
        $this->getJson('/account/authenticator')->assertOk()->assertJsonPath('recoveryRemaining', 9);
        $this->assertSame(1, $this->control->table('account_security_events')->where(['user_id' => $owner->id, 'event' => 'recovery_code.used'])->count());
        $this->assertSame(10, $this->control->table('account_recovery_codes')->where('user_id', $other->id)->whereNull('used_at')->count());
        $this->fresh($owner)->postJson('/account/authenticator/recover', ['recovery_code' => $codes[0]])->assertUnprocessable();
        $this->getJson('/workspaces')->assertForbidden();

        // Five failures in a row refuse even a correct code or recovery code for fifteen minutes.
        foreach (range(1, 4) as $attempt) {
            $this->postJson('/account/authenticator/verify', ['code' => '000000'])->assertUnprocessable();
        }
        $this->postJson('/account/authenticator/verify', ['code' => $this->code($secret)])->assertTooManyRequests();
        $this->postJson('/account/authenticator/recover', ['recovery_code' => $codes[1]])->assertTooManyRequests();
        $this->getJson('/workspaces')->assertForbidden();
        $this->assertSame(9, $this->control->table('account_recovery_codes')->where('user_id', $owner->id)->whereNull('used_at')->count());
    }

    public function test_recovery_codes_are_regenerated_and_an_authenticator_replaced_only_from_a_verified_session(): void
    {
        [$owner] = $this->owner();
        [$secret, $codes] = $this->enroll($owner);

        // Without the sign-in check neither change is possible, even with the password.
        $this->fresh($owner)->postJson('/account/authenticator/recovery-codes', ['password' => self::PASSWORD, 'code' => $this->code($secret)])->assertForbidden();
        $this->postJson('/account/authenticator/enroll', ['password' => self::PASSWORD])->assertForbidden();

        $this->postJson('/account/authenticator/recover', ['recovery_code' => $codes[0]])->assertOk();
        $this->postJson('/account/authenticator/recovery-codes', ['password' => 'wrong', 'code' => $this->code($secret)])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/account/authenticator/recovery-codes', ['password' => self::PASSWORD, 'code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $fresh = $this->postJson('/account/authenticator/recovery-codes', ['password' => self::PASSWORD, 'code' => $this->code($secret)])->assertOk()->json('recoveryCodes');
        $this->assertCount(10, $fresh);
        $this->assertEmpty(array_intersect($fresh, $codes));
        $this->fresh($owner)->postJson('/account/authenticator/recover', ['recovery_code' => $codes[1]])->assertUnprocessable();

        // A recovery-code sign-in can replace a lost authenticator: the old one stops working and the codes are renewed.
        $this->postJson('/account/authenticator/recover', ['recovery_code' => $fresh[0]])->assertOk();
        $this->control->table('sessions')->insert(['id' => 'owner-mfa-'.Str::uuid(), 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);
        $replacement = $this->postJson('/account/authenticator/enroll', ['password' => self::PASSWORD])->assertOk()->json('secret');
        $this->assertNotSame($secret, $replacement);
        $renewed = $this->postJson('/account/authenticator/verify', ['code' => $this->code($replacement)])->assertOk()->json('recoveryCodes');
        $this->assertCount(10, $renewed);
        $this->assertSame(0, $this->control->table('sessions')->where('user_id', $owner->id)->count());
        $this->assertSame(1, $this->control->table('account_security_events')->where(['user_id' => $owner->id, 'event' => 'authenticator.replaced'])->count());
        $this->control->table('users')->where('id', $owner->id)->update(['admin_totp_counter' => null]);
        $this->fresh($owner)->postJson('/account/authenticator/verify', ['code' => $this->code($secret)])->assertUnprocessable();
        $this->postJson('/account/authenticator/recover', ['recovery_code' => $fresh[1]])->assertUnprocessable();
        $this->postJson('/account/authenticator/verify', ['code' => $this->code($replacement)])->assertOk();
    }

    public function test_an_operator_resets_an_owner_but_never_itself_or_another_operator(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $operator = function (string $permissions) use ($secret): User {
            $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
            $this->control->table('platform_grants')->insert(['user_id' => $user->id, 'permissions' => $permissions]);
            $this->control->table('users')->where('id', $user->id)->update(['admin_totp_secret' => encrypt($secret), 'admin_totp_confirmed_at' => now()]);

            return $user->refresh();
        };
        $as = fn (User $user) => $this->fresh($user)->withSession(['account_mfa' => ['user' => $user->id], 'platform_mfa' => ['user' => $user->id, 'version' => 1, 'until' => time() + 900]]);
        $proof = function (array $extra = []) use ($secret): array {
            $this->control->table('users')->whereNotNull('admin_totp_counter')->update(['admin_totp_counter' => null]);

            return [...['reason' => 'Identity confirmed by verified email', 'password' => self::PASSWORD, 'code' => $this->code($secret)], ...$extra];
        };
        [$owner] = $this->owner();
        [, $codes] = $this->enroll($owner);
        $this->control->table('sessions')->insert(['id' => 'owner-mfa-'.Str::uuid(), 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);
        [$unenrolled] = $this->owner();
        $manager = $operator('{directory.read,access.manage}');
        $peer = $operator('{directory.read}');
        $path = fn (User $user) => '/admin/accounts/'.$user->id.'/reset-authenticator';

        $as($peer)->postJson($path($owner), $proof())->assertForbidden();
        $as($manager)->postJson($path($owner), ['reason' => 'No proof'])->assertUnprocessable();
        $this->postJson($path($owner), $proof(['code' => '000000']))->assertUnprocessable();
        $this->postJson($path($manager), $proof())->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($path($peer), $proof())->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($path($unenrolled), $proof())->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/admin/accounts/999999999/reset-authenticator', $proof())->assertNotFound();
        $this->assertNotNull($this->control->table('users')->where('id', $owner->id)->value('admin_totp_secret'));
        $this->assertNotNull($this->control->table('users')->where('id', $peer->id)->value('admin_totp_secret'));

        $this->postJson($path($owner), $proof())->assertOk()->assertJsonPath('ended', 1);
        $row = $this->control->table('users')->where('id', $owner->id)->first();
        $this->assertNull($row->admin_totp_secret);
        $this->assertNull($row->admin_totp_confirmed_at);
        $this->assertSame(0, $this->control->table('account_recovery_codes')->where('user_id', $owner->id)->count());
        $this->assertSame(1, $this->control->table('platform_audit')->where(['event' => 'account.authenticator_reset', 'target' => (string) $owner->id, 'actor_id' => $manager->id])->count());
        $this->assertSame(1, $this->control->table('account_security_events')->where(['user_id' => $owner->id, 'event' => 'authenticator.reset'])->count());

        // The owner is back to setup: the old recovery codes are gone and nothing else is reachable.
        $this->fresh($owner)->getJson('/workspaces')->assertForbidden();
        $this->postJson('/account/authenticator/recover', ['recovery_code' => $codes[0]])->assertUnprocessable();
        $this->getJson('/account/authenticator')->assertOk()->assertJson(['enrolled' => false, 'verified' => false]);

        // An account with a platform grant gets no recovery codes when it sets up an authenticator.
        $granted = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        app(Workspaces::class)->create($granted->id, 'Operator owner '.Str::uuid());
        $this->control->table('platform_grants')->insert(['user_id' => $granted->id, 'permissions' => '{directory.read}']);
        $this->flushSession();
        $own = $this->actingAs($granted)->postJson('/account/authenticator/enroll', ['password' => self::PASSWORD])->assertOk()->json('secret');
        $this->postJson('/account/authenticator/verify', ['code' => $this->code($own)])->assertOk()->assertJsonPath('recoveryCodes', [])->assertJsonPath('operator', true)->assertJsonPath('recoveryRemaining', null);
        $this->assertSame(0, $this->control->table('account_recovery_codes')->where('user_id', $granted->id)->count());
        $this->control->table('users')->where('id', $granted->id)->update(['admin_totp_counter' => null]);
        $this->postJson('/account/authenticator/recovery-codes', ['password' => self::PASSWORD, 'code' => $this->code($own)])->assertForbidden();
    }

    public function test_the_setting_cannot_switch_enforcement_off_in_production(): void
    {
        [$owner] = $this->owner();
        config(['security.owner_mfa' => false]);
        $this->actingAs($owner)->getJson('/workspaces')->assertOk();

        $this->app['env'] = 'production';
        $this->getJson('/workspaces')->assertForbidden()->assertJsonPath('message', self::BLOCKED);
        // Staging runs the production build with staging mode; there the setting applies.
        config(['staging.enabled' => true]);
        $this->getJson('/workspaces')->assertOk();
        config(['security.owner_mfa' => true]);
        $this->getJson('/workspaces')->assertForbidden();
    }

    public function test_the_runtime_role_cannot_read_or_write_recovery_codes_or_events_directly(): void
    {
        [$owner] = $this->owner();
        [, $codes] = $this->enroll($owner);
        $this->assertSame(10, $this->control->table('account_recovery_codes')->where('user_id', $owner->id)->count());
        $this->assertSame(0, DB::table('account_recovery_codes')->count());
        $this->assertSame(0, DB::table('account_security_events')->count());
        $this->assertSame(0, DB::table('account_recovery_codes')->where('user_id', $owner->id)->update(['used_at' => null]));
        $this->assertSame(0, DB::table('account_recovery_codes')->where('user_id', $owner->id)->delete());
        foreach ([fn () => DB::table('account_recovery_codes')->insert(['user_id' => $owner->id, 'code_hash' => str_repeat('a', 64)]),
            fn () => DB::table('account_security_events')->insert(['user_id' => $owner->id, 'event' => 'authenticator.reset']),
            fn () => DB::select('SELECT app_recovery_digest(?)', [$codes[0]]),
            fn () => DB::select('SELECT app_account_set_recovery_codes(?::text[])', ['{AAAAA-AAAAA}']),
            fn () => DB::select('SELECT app_platform_reset_authenticator(?,?)', [$owner->id, 'No grant'])] as $attempt) {
            try {
                $attempt();
                $this->fail('The runtime role changed protected authenticator data.');
            } catch (QueryException $error) {
                $this->assertContains($error->errorInfo[0], ['42501']);
            }
        }
        $this->assertSame(10, $this->control->table('account_recovery_codes')->where('user_id', $owner->id)->whereNull('used_at')->count());
    }
}
