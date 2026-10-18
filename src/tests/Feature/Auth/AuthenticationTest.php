<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_hashes_password_and_requests_verification(): void
    {
        Notification::fake();
        $this->postJson('/register', [
            'name' => 'Creator', 'email' => 'creator@example.test',
            'password' => 'safe-password-123', 'password_confirmation' => 'safe-password-123',
        ])->assertCreated();
        $user = User::firstOrFail();
        $this->assertTrue(Hash::check('safe-password-123', $user->password));
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_registration_does_not_create_identity(): void
    {
        $this->postJson('/register', ['name' => 'Creator', 'email' => 'bad', 'password' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_logout_and_private_account_projection(): void
    {
        $user = User::factory()->create(['password' => 'safe-password-123']);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'safe-password-123'])->assertOk();
        $this->getJson('/account')->assertOk()->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token');
        $this->postJson('/logout')->assertOk();
        $this->assertGuest();
        $this->getJson('/account')->assertUnauthorized();
    }

    public function test_failed_login_is_rate_limited(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
        // A correct password is also blocked until the throttle expires.
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
    }

    public function test_unverified_identity_cannot_use_workspace_routes(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->getJson('/workspaces')->assertForbidden();
    }

    public function test_signed_verification_link_is_required_and_bound_to_the_user(): void
    {
        $user = User::factory()->unverified()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
        $this->actingAs($user)->getJson($link.'&tampered=true')->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->getJson($link)->assertOk();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_or_other_users_verification_links_are_denied(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
        $this->actingAs($user)->getJson($link)->assertForbidden();
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $other->id, 'hash' => sha1($other->email),
        ]);
        $this->getJson($link)->assertForbidden();
    }

    public function test_recovery_has_a_generic_response_for_unknown_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $existing = $this->postJson('/forgot-password', ['email' => $user->email])->assertOk()->json();
        $missing = $this->postJson('/forgot-password', ['email' => 'missing@example.test'])->assertOk()->json();
        $this->assertSame($existing, $missing);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_reset_invalidates_tokens_and_all_database_sessions(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        DB::table('sessions')->insert([
            'id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
        ]);
        $payload = ['email' => $user->email, 'token' => $token,
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'];
        $this->postJson('/reset-password', [...$payload, 'token' => 'invalid'])->assertUnprocessable();
        $this->postJson('/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->postJson('/reset-password', $payload)->assertUnprocessable();
    }

    public function test_session_revocation_requires_current_password_and_logs_out(): void
    {
        $user = User::factory()->create(['password' => 'safe-password-123']);
        DB::table('sessions')->insert(['id' => 'another-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($user)->deleteJson('/sessions', ['password' => 'wrong'])->assertUnprocessable();
        $this->assertDatabaseHas('sessions', ['id' => 'another-session']);
        $this->deleteJson('/sessions', ['password' => 'safe-password-123'])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'another-session']);
        $this->assertGuest();
    }

    public function test_expired_password_reset_tokens_cannot_change_passwords(): void
    {
        $user = User::factory()->create();
        $original = $user->password;
        $token = Password::createToken($user);
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subDays(2)]);
        $this->postJson('/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertUnprocessable();
        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_duplicate_registration_does_not_replace_an_account(): void
    {
        $user = User::factory()->create();
        $this->postJson('/register', [
            'name' => 'Other', 'email' => $user->email,
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($user->name, $user->fresh()->name);
    }

    public function test_web_mutations_require_csrf_outside_the_test_bypass(): void
    {
        $this->app->instance('env', 'local');
        $this->postJson('/login', ['email' => 'creator@example.test', 'password' => 'wrong'])->assertStatus(419);
    }

    public function test_recovery_throttling_does_not_consume_registration_allowance(): void
    {
        Notification::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/forgot-password', ['email' => 'missing@example.test'])->assertOk();
        }
        $this->postJson('/forgot-password', ['email' => 'missing@example.test'])->assertTooManyRequests();
        $this->postJson('/register', [
            'name' => 'Creator', 'email' => 'creator@example.test',
            'password' => 'safe-password-123', 'password_confirmation' => 'safe-password-123',
        ])->assertCreated();
    }
}
