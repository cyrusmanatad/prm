<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\Workspaces;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** Personal accent color against the real runtime role: own-row writes, the database allowlist and account guards. */
class AppearancePreferenceTest extends AcceptanceCase
{
    private const PASSWORD = 'SyntheticPassword123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['security.owner_mfa' => false]);
    }

    private function appearance(User $user): string
    {
        return $this->control->table('users')->where('id', $user->id)->value('appearance');
    }

    public function test_the_runtime_saves_only_its_own_row_and_workspace_changes_keep_the_choice(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $first = app(Workspaces::class)->create($user->id, 'Appearance one '.Str::uuid());
        $second = app(Workspaces::class)->create($user->id, 'Appearance two '.Str::uuid());
        $this->assertSame('burgundy', $this->appearance($user));

        $this->actingAs($user)->postJson('/account/appearance', ['appearance' => 'purple', 'user_id' => $other->id])->assertOk();
        $this->assertSame('purple', $this->appearance($user));
        $this->assertSame('burgundy', $this->appearance($other));

        foreach ([$first, $second] as $org) {
            $this->actingAs($user->refresh())->withSession(['active_org_id' => $org])->get('/account/appearance')
                ->assertSee('data-appearance="purple"', false)
                ->assertInertia(fn (Assert $page) => $page->where('appearance', 'purple')->where('activeWorkspace.name', fn ($name) => str_starts_with($name, 'Appearance'))->etc());
        }
        $this->flushSession();
        $this->actingAs($other->refresh())->get('/account/appearance')->assertSee('data-appearance="burgundy"', false);
    }

    public function test_the_database_refuses_values_outside_the_allowlist_from_the_runtime_role(): void
    {
        $user = User::factory()->create();
        foreach (['teal', 'Purple', ''] as $value) {
            try {
                DB::transaction(fn () => DB::table('users')->where('id', $user->id)->update(['appearance' => $value]));
                $this->fail('Stored an unsupported appearance: '.$value);
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
            }
        }
        $this->assertSame('burgundy', $this->appearance($user));
    }

    public function test_suspended_closing_and_unverified_owner_sessions_cannot_change_it(): void
    {
        $suspended = User::factory()->create();
        $this->control->table('platform_suspensions')->insert(['subject' => 'account', 'user_id' => $suspended->id, 'reason' => 'Synthetic suspension', 'suspended_by' => $suspended->id]);
        $this->actingAs($suspended)->postJson('/account/appearance', ['appearance' => 'purple'])->assertForbidden();
        $this->assertSame('burgundy', $this->appearance($suspended));

        $closing = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $preview = $this->actingAs($closing)->getJson('/account/closure')->assertOk()->json('preview');
        $this->postJson('/account/closure', ['password' => self::PASSWORD, 'scope' => 'account', 'manifest_hash' => $preview['hash'], 'reason' => 'Synthetic closure request'])->assertCreated();
        $this->actingAs($closing->refresh())->postJson('/account/appearance', ['appearance' => 'purple'])->assertForbidden();
        $this->get('/account/appearance')->assertRedirect('/account/closure');
        $this->assertSame('burgundy', $this->appearance($closing));

        // A workspace owner who has not passed the authenticator check reaches only the authenticator page.
        config(['security.owner_mfa' => true]);
        $this->flushSession();
        $owner = User::factory()->create();
        app(Workspaces::class)->create($owner->id, 'Appearance MFA '.Str::uuid());
        $this->actingAs($owner)->postJson('/account/appearance', ['appearance' => 'purple'])->assertForbidden();
        $this->assertSame('burgundy', $this->appearance($owner));
    }
}
