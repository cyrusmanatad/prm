<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConcurrentMembershipTest extends AcceptanceCase
{
    public static function changes(): array
    {
        return ['revocation' => ['revoke'], 'demotion' => ['demote']];
    }

    #[DataProvider('changes')]
    public function test_in_flight_action_finishes_before_membership_change_commits(string $change): void
    {
        $actor = User::factory()->create();
        $org = app(Workspaces::class)->create($actor->id, 'Before concurrency');
        $updater = null;
        try {
            app(TenantContext::class)->run($actor->id, $org, function () use ($actor, $org, $change, &$updater): void {
                $updater = $this->probe($change, $actor->id, $org, control: true);
                $this->assertBlocked($updater);
                app(Workspaces::class)->rename($actor->id, $org, 'Authorized in-flight write');
            });
            $this->assertSame(200, $this->probeResult($updater)['status']);
            $this->assertDeniedAndClean($actor->id, $org);
            $this->assertSame('Authorized in-flight write', $this->control->table('organizations')->where('id', $org)->value('name'));
            $this->assertSame(1, $this->control->table('audit_logs')->where('org_id', $org)->where('event', 'workspace.renamed')->count());
            $this->actingAs($actor)->withSession(['active_org_id' => $org])
                ->patchJson('/workspace', ['name' => 'Stale session write'])->assertForbidden();
            if ($change === 'revoke') {
                $this->getJson('/workspaces')->assertExactJson(['workspaces' => []]);
            }
        } finally {
            $updater?->stop(0);
        }
    }

    #[DataProvider('changes')]
    public function test_membership_change_that_wins_the_lock_denies_waiting_action(string $change): void
    {
        $actor = User::factory()->create();
        $org = app(Workspaces::class)->create($actor->id, 'Before concurrency');
        $this->control->beginTransaction();
        $this->control->table('memberships')->where('org_id', $org)->where('user_id', $actor->id)
            ->update($change === 'revoke' ? ['revoked_at' => now()] : ['role' => 'viewer']);
        $waiting = $this->probe('rename', $actor->id, $org);
        try {
            $this->assertBlocked($waiting);
            $this->control->commit();
            $this->assertSame(['status' => 403, 'actor' => null, 'org' => null, 'transactions' => 0], $this->probeResult($waiting));
            $this->assertSame('Before concurrency', $this->control->table('organizations')->where('id', $org)->value('name'));
            $this->assertSame(0, $this->control->table('audit_logs')->where('org_id', $org)->where('event', 'workspace.renamed')->count());
        } finally {
            if ($this->control->transactionLevel() > 0) {
                $this->control->rollBack();
            }
            $waiting->stop(0);
        }
    }

    public function test_lock_function_cannot_lock_another_workspace_or_another_actors_membership(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $own = app(Workspaces::class)->create($a->id, 'Own');
        $foreign = app(Workspaces::class)->create($b->id, 'Foreign');
        app(TenantContext::class)->run($a->id, $own, function () use ($foreign): void {
            $this->assertFalse(DB::selectOne('SELECT app_lock_membership(?::uuid) AS allowed', [$foreign])->allowed);
        });
        app(TenantContext::class)->run($a->id, $foreign, function () use ($foreign): void {
            $this->assertFalse(DB::selectOne('SELECT app_lock_membership(?::uuid) AS allowed', [$foreign])->allowed);
        }, requireMembership: false);
        $this->assertFalse(DB::selectOne('SELECT app_lock_membership(NULL) AS allowed')->allowed);
    }

    public function test_failed_action_releases_its_membership_lock_and_rolls_back_its_audit(): void
    {
        $actor = User::factory()->create();
        $org = app(Workspaces::class)->create($actor->id, 'Before rollback');
        $updater = null;
        try {
            try {
                app(TenantContext::class)->run($actor->id, $org, function () use ($actor, $org, &$updater): void {
                    $updater = $this->probe('revoke', $actor->id, $org, control: true);
                    $this->assertBlocked($updater);
                    app(Workspaces::class)->rename($actor->id, $org, 'Must roll back');
                    throw new LogicException('Rollback barrier');
                });
                $this->fail('Exception was swallowed.');
            } catch (LogicException $exception) {
                $this->assertSame('Rollback barrier', $exception->getMessage());
            }
            $this->assertSame(200, $this->probeResult($updater)['status']);
            $this->assertDeniedAndClean($actor->id, $org);
            $this->assertSame('Before rollback', $this->control->table('organizations')->where('id', $org)->value('name'));
            $this->assertSame(0, $this->control->table('audit_logs')->where('org_id', $org)->where('event', 'workspace.renamed')->count());
        } finally {
            $updater?->stop(0);
        }
    }

    private function assertDeniedAndClean(int $actor, string $org): void
    {
        try {
            app(TenantContext::class)->run($actor, $org, fn () => app(Workspaces::class)->rename($actor, $org, 'Denied write'));
            $this->fail('Changed membership accepted a new write.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertNull(DB::selectOne('SELECT app_actor() AS actor')->actor);
        $this->assertNull(DB::selectOne('SELECT app_org() AS org')->org);
        $this->assertSame(0, DB::connection()->transactionLevel());
    }
}
