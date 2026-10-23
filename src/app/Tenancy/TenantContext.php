<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Transaction-local settings never survive a request/job commit or rollback. */
class TenantContext
{
    public function run(int $actorId, ?string $orgId, Closure $callback, bool $requireMembership = true): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new LogicException('Tenant operations require PostgreSQL.');
        }
        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Tenant context must own the outermost transaction.');
        }

        $unsafe = $connection->selectOne("SELECT rolsuper OR rolbypassrls OR rolcreaterole OR EXISTS (
            SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relrowsecurity
              AND pg_has_role(current_user, c.relowner, 'USAGE')
        ) AS unsafe FROM pg_roles WHERE rolname = current_user");
        if (! $unsafe || $unsafe->unsafe) {
            throw new LogicException('Tenant operations require a restricted non-owner runtime role.');
        }

        return $connection->transaction(function () use ($connection, $actorId, $orgId, $callback, $requireMembership) {
            $this->set($connection, $actorId, $orgId);
            if ($requireMembership && $orgId !== null) {
                $member = $connection->selectOne('SELECT app_lock_membership(?::uuid) AS allowed', [$orgId]);
                abort_unless($member?->allowed, 403, 'Workspace access is no longer available.');
            }

            return $callback();
        });
    }

    private function set(ConnectionInterface $connection, int $actorId, ?string $orgId): void
    {
        $connection->selectOne("SELECT set_config('app.actor_id', ?, true), set_config('app.org_id', ?, true)", [
            (string) $actorId, $orgId ?? '',
        ]);
    }
}
