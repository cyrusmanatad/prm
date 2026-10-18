<?php

namespace Tests\Integration;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Workspaces;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaWorkspaceTest extends TestCase
{
    public function test_page_and_partial_reload_expose_only_current_actors_workspace_projection(): void
    {
        $this->withoutVite();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $service = app(Workspaces::class);
        $own = $service->create($a->id, 'Visible workspace');
        $foreign = $service->create($b->id, 'Private foreign name');
        $this->actingAs($a)->withSession(['active_org_id' => $foreign])->get('/workspaces')
            ->assertSessionMissing('active_org_id')->assertInertia(fn (Assert $page) => $page
            ->component('Workspaces')->where('account', $a->only(['name', 'email']))
            ->where('activeOrgId', null)->where('accessRevoked', true)
            ->where('workspaces', [['id' => $own, 'name' => 'Visible workspace', 'role' => 'owner']])->etc());
        $version = app(HandleInertiaRequests::class)->version(request());
        $this->get('/workspaces', [
            'X-Inertia' => 'true', 'X-Inertia-Version' => $version ?? '',
            'X-Inertia-Partial-Component' => 'Workspaces', 'X-Inertia-Partial-Data' => 'workspaces',
        ])->assertOk()->assertJsonPath('props.workspaces.0.id', $own)
            ->assertJsonCount(1, 'props.workspaces')->assertJsonMissingPath('props.account')
            ->assertDontSee('Private foreign name');
        $this->assertNull(DB::selectOne('SELECT app_actor() AS actor')->actor);
        $this->assertNull(DB::selectOne('SELECT app_org() AS org')->org);
    }

    public function test_patch_redirects_with_named_validation_errors_then_updates_within_tenant_context(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $id = app(Workspaces::class)->create($user->id, 'Original');
        $this->actingAs($user)->withSession(['active_org_id' => $id])->from('/workspaces')
            ->patch('/workspace', ['name' => ''], ['X-Inertia' => 'true', 'X-Inertia-Error-Bag' => 'rename'])
            ->assertStatus(303)->assertRedirect('/workspaces')->assertSessionHasErrors('name');
        $this->withCookie(config('session.cookie'), session()->getId())->get('/workspaces', ['X-Inertia-Error-Bag' => 'rename'])
            ->assertInertia(fn (Assert $page) => $page->has('errors.rename.name')->etc());
        $this->patch('/workspace', ['name' => 'Updated'], ['X-Inertia' => 'true'])
            ->assertStatus(303)->assertRedirect('/workspaces');
        $this->get('/workspaces')->assertInertia(fn (Assert $page) => $page
            ->where('workspaces.0.name', 'Updated')->where('activeOrgId', $id)->etc());
    }
}
