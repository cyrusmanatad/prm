<?php

namespace Tests\Integration;

use App\Jobs\Middleware\TenantJobContext;
use App\Models\User;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/** Requires a migrated disposable PostgreSQL database and the restricted runtime role. */
class WorkspaceTest extends TestCase
{
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
