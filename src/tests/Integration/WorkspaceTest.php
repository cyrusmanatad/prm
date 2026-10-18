<?php

namespace Tests\Integration;

use App\Jobs\Middleware\TenantJobContext;
use App\Models\User;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Requires a migrated disposable PostgreSQL database and the restricted runtime role. */
class WorkspaceTest extends TestCase
{
    public function test_switching_workspaces_rechecks_identity_and_preserves_selection_on_denial(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $this->actingAs($owner);
        $first = $this->postJson('/workspaces', ['name' => 'First'])->assertCreated()->json('id');
        $second = $this->postJson('/workspaces', ['name' => 'Second'])->assertCreated()->json('id');
        $this->postJson('/workspace/select', ['org_id' => $first])->assertOk()
            ->assertSessionHas('active_org_id', $first);
        $foreign = app(Workspaces::class)->create($outsider->id, 'Foreign');
        $this->postJson('/workspace/select', ['org_id' => $foreign])->assertForbidden()
            ->assertSessionHas('active_org_id', $first);
        $this->patchJson('/workspace', ['name' => 'First updated'])->assertOk();
        $this->postJson('/workspace/select', ['org_id' => $second])->assertOk();
        $this->patchJson('/workspace', ['name' => 'Second updated'])->assertOk();
        $this->actingAs($outsider)->withSession(['active_org_id' => $first])
            ->patchJson('/workspace', ['name' => 'Stale selection'])->assertForbidden();
        $rows = app(Workspaces::class)->listing($owner->id);
        $this->assertSame(['First updated', 'Second updated'], array_column($rows, 'name'));
        $this->assertNull(DB::selectOne('SELECT app_org() AS org')->org);
        $this->assertNull(DB::selectOne('SELECT app_actor() AS actor')->actor);
    }

    public function test_failed_job_rolls_back_writes_and_audit_before_the_next_actor_runs(): void
    {
        $actor = User::factory()->create();
        $orgId = app(Workspaces::class)->create($actor->id, 'Before failure');
        $middleware = new TenantJobContext;
        $job = (object) ['actorId' => $actor->id, 'orgId' => $orgId];
        try {
            $middleware->handle($job, function () use ($actor, $orgId): void {
                app(Workspaces::class)->rename($actor->id, $orgId, 'Must roll back');
                throw new LogicException('Failure after write');
            });
            $this->fail('Job failure was swallowed');
        } catch (LogicException $exception) {
            $this->assertSame('Failure after write', $exception->getMessage());
        }
        $this->assertNull(DB::selectOne('SELECT app_org() AS org')->org);
        $this->assertNull(DB::selectOne('SELECT app_actor() AS actor')->actor);
        $middleware->handle($job, function (): void {
            $this->assertSame('Before failure', DB::table('organizations')->value('name'));
            $this->assertSame(0, DB::table('audit_logs')->where('event', 'workspace.renamed')->count());
        });
        $outsider = User::factory()->create();
        try {
            $middleware->handle((object) ['actorId' => $outsider->id, 'orgId' => $orgId], function (): void {
                $this->fail('An unauthorized job reached its handler');
            });
            $this->fail('An unauthorized job was accepted');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertNull(DB::selectOne('SELECT app_actor() AS actor')->actor);
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public function test_http_workspace_isolation_and_worker_context_cleanup(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('weavdash_app', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->actingAs($a)->getJson('/workspaces')->assertOk()->assertExactJson(['workspaces' => []]);
        $orgA = $this->postJson('/workspaces', ['name' => 'Creator A'])->assertCreated()->json('id');
        $this->assertTrue(Str::isUuid($orgA));
        $this->patchJson('/workspace', ['name' => 'Creator A renamed'])->assertOk();
        $orgB = $this->actingAs($b)->postJson('/workspaces', ['name' => 'Creator B'])->assertCreated()->json('id');
        $this->getJson('/workspaces')->assertOk()->assertJsonCount(1, 'workspaces')->assertJsonPath('workspaces.0.id', $orgB);
        $this->postJson('/workspace/select', ['org_id' => $orgA])->assertForbidden();
        $this->postJson('/workspace/select', ['org_id' => 'invalid'])->assertUnprocessable();
        $this->withSession(['active_org_id' => $orgA])->patchJson('/workspace', ['name' => 'forged'])->assertForbidden();
        $this->assertNull(DB::selectOne('SELECT app_org() AS org')->org);
        $context = app(TenantContext::class);
        $middleware = new TenantJobContext;
        foreach ([[$a->id, $orgA], [$b->id, $orgB]] as [$actorId, $orgId]) {
            $job = (object) compact('actorId', 'orgId');
            $middleware->handle($job, function () use ($orgId): void {
                $this->assertSame($orgId, DB::selectOne('SELECT app_org() AS org')->org);
                $this->assertSame(1, DB::table('audit_logs')->where('org_id', $orgId)->where('event', 'workspace.created')->count());
                $this->assertSame(0, DB::table('audit_logs')->where('org_id', '!=', $orgId)->count());
            });
            $this->assertNull(DB::selectOne('SELECT app_org() AS org')->org);
        }
        try {
            $context->run($a->id, $orgA, function (): void {
                throw new LogicException('Simulated job failure');
            });
            $this->fail('Exception was swallowed');
        } catch (LogicException $exception) {
            $this->assertSame('Simulated job failure', $exception->getMessage());
        }
        $this->assertNull(DB::selectOne('SELECT app_org() AS org')->org);
        $this->assertNull(DB::selectOne('SELECT app_actor() AS actor')->actor);
        $this->assertCount(1, app(Workspaces::class)->listing($a->id));
    }
}
