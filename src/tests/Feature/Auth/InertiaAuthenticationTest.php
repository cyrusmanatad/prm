<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guest_pages_and_reset_notification_destination_render_the_application(): void
    {
        $this->get('/')->assertRedirect('/login');
        foreach (['login', 'register', 'forgot-password'] as $mode) {
            $this->get('/'.$mode)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Auth')->where('mode', $mode)->where('account', null)
                ->missing('password')->missing('user')->etc());
        }
        $response = $this->get('/reset-password/synthetic-token?email=creator@example.test');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth')
            ->where('mode', 'reset-password')->where('token', 'synthetic-token')
            ->where('email', 'creator@example.test')->etc());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertTrue($response->viewData('page')['encryptHistory']);
    }

    public function test_browser_registration_redirects_to_verification_with_minimal_account_props(): void
    {
        Notification::fake();
        $this->post('/register', [
            'name' => 'Creator', 'email' => 'creator@example.test',
            'password' => 'safe-password-123', 'password_confirmation' => 'safe-password-123',
        ], ['X-Inertia' => 'true'])->assertRedirect('/verify-email');
        $this->get('/verify-email')->assertInertia(fn (Assert $page) => $page->component('Auth')
            ->where('mode', 'verify-email')->where('account', ['name' => 'Creator', 'email' => 'creator@example.test'])
            ->missing('account.password')->missing('account.remember_token')->etc());
        $this->get('/workspaces')->assertRedirect('/verify-email');
    }

    public function test_validation_errors_redirect_and_never_flash_passwords(): void
    {
        $this->from('/register')->post('/register', [
            'name' => 'Creator', 'email' => 'invalid', 'password' => 'short', 'password_confirmation' => 'different',
        ], ['X-Inertia' => 'true'])->assertRedirect('/register')->assertSessionHasErrors(['email', 'password']);
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
        $this->withCookie(config('session.cookie'), session()->getId())->get('/register')->assertInertia(fn (Assert $page) => $page->has('errors.email')
            ->has('errors.password')->missing('password')->etc());
    }

    public function test_browser_login_routes_by_verification_and_logout_clears_history(): void
    {
        $user = User::factory()->unverified()->create(['password' => 'safe-password-123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'safe-password-123'])
            ->assertRedirect('/verify-email');
        $this->post('/logout', [], ['X-Inertia' => 'true'])->assertRedirect('/login');
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('account', null)->etc());
        $user->markEmailAsVerified();
        $this->post('/login', ['email' => $user->email, 'password' => 'safe-password-123'])
            ->assertRedirect('/workspaces');
    }

    public function test_browser_recovery_response_is_generic(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        foreach ([$user->email, 'missing@example.test'] as $email) {
            $this->post('/forgot-password', ['email' => $email])->assertRedirect('/forgot-password')
                ->assertSessionHas('status', 'If the account exists, a reset link has been requested.');
        }
    }

    public function test_denied_browser_verification_uses_a_generic_inertia_error_page(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get('/verify-email/'.$user->id.'/invalid?signature=invalid')
            ->assertForbidden()->assertInertia(fn (Assert $page) => $page->component('Error')
            ->where('statusCode', 403)->where('message', 'This page is not available to your account.')
            ->missing('exception')->missing('trace')->etc());
    }
}
