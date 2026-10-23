<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortalEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_entry_points_reuse_auth_and_discard_arbitrary_intended_redirects(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['password' => 'safe-password-123']);
        foreach (['partner', 'brand'] as $portal) {
            $this->get('/'.$portal.'/login')->assertInertia(fn (Assert $page) => $page->component('Auth')->where('mode', 'login')->where('portal', $portal)->etc());
            $this->withSession(['url.intended' => 'https://untrusted.example/steal'])
                ->post('/'.$portal.'/login', ['email' => $user->email, 'password' => 'safe-password-123'])->assertRedirect('/portal/enter')->assertSessionMissing('url.intended')->assertSessionHas('portal', $portal);
            $this->post('/logout')->assertRedirect('/login');
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_partner_registration_persists_creator_provisioning_and_duplicate_registration_reuses_existing_account_flow(): void
    {
        $this->withoutVite();
        $data = ['name' => 'Partner', 'email' => 'partner@example.test', 'password' => 'safe-password-123', 'password_confirmation' => 'safe-password-123'];
        $this->post('/partner/register', $data)->assertRedirect('/verify-email');
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame('creator', $user->registration_workspace_kind);
        $this->assertNotNull($user->registration_workspace_id);
        $this->get('/partner')->assertRedirect('/verify-email');
        $this->post('/logout');
        $this->post('/brand/register', $data)->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
    }
}
