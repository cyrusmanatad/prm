<?php

namespace Tests\Feature;

use App\Enums\Appearance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppearancePreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_accounts_default_to_burgundy_without_saving(): void
    {
        $user = User::factory()->create();
        $this->assertSame('burgundy', DB::table('users')->where('id', $user->id)->value('appearance'));
        $this->actingAs($user)->get('/account/appearance')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('AccountAppearance')
                ->where('appearance', 'burgundy')->where('account', ['name' => $user->name, 'email' => $user->email])->etc());
        $this->actingAs($user)->getJson('/account/appearance')->assertExactJson(['appearance' => 'burgundy']);
    }

    public function test_saved_choice_persists_and_reaches_the_initial_html_before_hydration(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->from('/account/appearance')->post('/account/appearance', ['appearance' => 'purple'], ['X-Inertia' => 'true'])
            ->assertRedirect('/account/appearance')->assertSessionHas('status', 'Appearance saved');
        $this->assertSame('purple', DB::table('users')->where('id', $user->id)->value('appearance'));

        $html = $this->actingAs($user->fresh())->get('/account/appearance');
        $html->assertOk()->assertSee('data-appearance="purple"', false)
            ->assertInertia(fn (Assert $page) => $page->where('appearance', 'purple')->etc());

        // Switching back restores the default.
        $this->actingAs($user->fresh())->postJson('/account/appearance', ['appearance' => 'burgundy'])
            ->assertOk()->assertExactJson(['appearance' => 'burgundy', 'message' => 'Appearance saved']);
        $this->actingAs($user->fresh())->get('/account/appearance')->assertSee('data-appearance="burgundy"', false);
    }

    public function test_choice_survives_sign_out_and_sign_in_without_reaching_the_next_account(): void
    {
        $first = User::factory()->create(['password' => 'safe-password-123']);
        $second = User::factory()->create(['password' => 'safe-password-123']);
        $this->post('/login', ['email' => $first->email, 'password' => 'safe-password-123'])->assertRedirect('/workspaces');
        $this->postJson('/account/appearance', ['appearance' => 'purple'])->assertOk();
        $this->get('/account/appearance')->assertSee('data-appearance="purple"', false);

        $this->post('/logout', [], ['X-Inertia' => 'true'])->assertRedirect('/login');
        $this->get('/login')->assertSee('data-appearance="burgundy"', false)
            ->assertInertia(fn (Assert $page) => $page->where('appearance', 'burgundy')->etc());

        // The next account in the same browser gets its own (default) choice.
        $this->post('/login', ['email' => $second->email, 'password' => 'safe-password-123'])->assertRedirect('/workspaces');
        $this->get('/account/appearance')->assertSee('data-appearance="burgundy"', false)
            ->assertInertia(fn (Assert $page) => $page->where('appearance', 'burgundy')->etc());
        $this->post('/logout');

        $this->post('/login', ['email' => $first->email, 'password' => 'safe-password-123']);
        $this->get('/account/appearance')->assertSee('data-appearance="purple"', false);
    }

    public function test_only_the_signed_in_account_changes_and_submitted_identifiers_are_ignored(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $before = DB::table('users')->where('id', $user->id)->first();
        $this->actingAs($user)->postJson('/account/appearance', [
            'appearance' => 'purple', 'user_id' => $other->id, 'id' => $other->id, 'name' => 'Changed', 'email' => 'changed@example.test',
        ])->assertOk();

        $after = DB::table('users')->where('id', $user->id)->first();
        $this->assertSame('purple', $after->appearance);
        $this->assertSame([$before->name, $before->email, $before->password], [$after->name, $after->email, $after->password]);
        $this->assertSame('burgundy', DB::table('users')->where('id', $other->id)->value('appearance'));
    }

    public function test_invalid_values_are_rejected_and_keep_the_saved_choice(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['appearance' => 'purple']);
        foreach ([null, '', 'teal', 'PURPLE', '#641d73', 'purple; color: red', ['purple']] as $value) {
            $this->actingAs($user)->postJson('/account/appearance', ['appearance' => $value])
                ->assertUnprocessable()->assertJsonValidationErrors('appearance');
        }
        $this->actingAs($user)->from('/account/appearance')->post('/account/appearance', ['appearance' => 'teal'], ['X-Inertia' => 'true'])
            ->assertRedirect('/account/appearance')->assertSessionHasErrors('appearance');
        $this->assertSame('purple', DB::table('users')->where('id', $user->id)->value('appearance'));
    }

    public function test_guests_unverified_accounts_and_missing_csrf_cannot_change_it(): void
    {
        $this->get('/account/appearance')->assertRedirect('/login');
        $this->post('/account/appearance', ['appearance' => 'purple'])->assertRedirect('/login');

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->get('/account/appearance')->assertRedirect('/verify-email');
        $this->actingAs($unverified)->post('/account/appearance', ['appearance' => 'purple'])->assertRedirect('/verify-email');
        $this->assertSame('burgundy', DB::table('users')->where('id', $unverified->id)->value('appearance'));
    }

    public function test_unknown_stored_values_and_public_pages_fall_back_to_burgundy(): void
    {
        $this->assertSame(Appearance::Burgundy, Appearance::normalize(null));
        $this->assertSame(Appearance::Burgundy, Appearance::normalize('teal'));
        $this->assertSame(Appearance::Burgundy, Appearance::normalize(['purple']));
        $this->assertSame(Appearance::Purple, Appearance::normalize('purple'));

        $user = User::factory()->create();
        $user->setRawAttributes([...$user->getAttributes(), 'appearance' => 'teal'], true);
        $this->actingAs($user)->get('/account/appearance')->assertSee('data-appearance="burgundy"', false)
            ->assertInertia(fn (Assert $page) => $page->where('appearance', 'burgundy')->etc());

        // Shared invitation, onboarding and lead pages keep the default accent even for a signed-in account.
        $purple = User::factory()->create();
        DB::table('users')->where('id', $purple->id)->update(['appearance' => 'purple']);
        foreach (['/onboarding', '/lead'] as $path) {
            $this->actingAs($purple->fresh())->get($path)->assertOk()->assertSee('data-appearance="burgundy"', false)
                ->assertInertia(fn (Assert $page) => $page->where('appearance', 'burgundy')->etc());
        }
    }

    public function test_partial_reloads_keep_the_existing_value_on_the_client(): void
    {
        Route::middleware('web')->get('/_appearance-probe', fn () => Inertia::render('AccountAppearance', ['appearance' => 'purple', 'other' => 1]));
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['appearance' => 'purple']);
        $version = $this->actingAs($user->fresh())->get('/_appearance-probe')->viewData('page')['version'];
        // A partial reload omits shared appearance; the client keeps the value it already applied.
        $this->actingAs($user->fresh())->get('/_appearance-probe', [
            'X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'AccountAppearance', 'X-Inertia-Partial-Data' => 'other',
        ])->assertOk()->assertJsonPath('props.other', 1)->assertJsonMissingPath('props.appearance');
    }
}
