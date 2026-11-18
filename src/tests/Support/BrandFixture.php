<?php

namespace Tests\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Synthetic approved Brand workspace for disposable databases. Brand workspaces need recorded approval
 * (D37), so fixtures write the workspace with rollout-style provenance instead of using the runtime
 * creation path, which refuses them.
 */
class BrandFixture
{
    /** With a migration-role connection (given, or from the acceptance control credential), or else through the `test_approved_brand` helper that rls-fixtures.sql installs. */
    public static function create(int $ownerId, string $name, ?ConnectionInterface $privileged = null): string
    {
        $org = (string) Str::uuid();
        // Suites run by the acceptance service have the control credential and need no installed helper.
        $privileged ??= getenv('TEST_MIGRATION_DB_PASSWORD') ? self::control() : null;
        if (! $privileged) {
            DB::selectOne('SELECT test_approved_brand(?::bigint,?::uuid,?::text)', [$ownerId, $org, $name]);

            return $org;
        }
        $privileged->transaction(function () use ($privileged, $ownerId, $name, $org): void {
            $request = (string) Str::uuid();
            $privileged->table('organizations')->insert(['id' => $org, 'name' => $name, 'kind' => 'brand']);
            $privileged->table('memberships')->insert(['org_id' => $org, 'user_id' => $ownerId, 'role' => 'owner']);
            $privileged->table('brand_requests')->insert(['id' => $request, 'source' => 'rollout', 'status' => 'approved', 'org_id' => $org, 'decided_at' => now()]);
            $privileged->table('brand_request_events')->insert(['id' => (string) Str::uuid(), 'request_id' => $request, 'version' => 1, 'event' => 'rollout_approved',
                'operator' => 'synthetic-fixture', 'reason' => 'Synthetic fixture workspace']);
        });

        return $org;
    }

    /** Migration-role connection for tests that run with the acceptance control credential. */
    public static function control(): ConnectionInterface
    {
        config(['database.connections.brand_fixture' => [...config('database.connections.pgsql'), 'url' => null,
            'username' => 'weavdash_migrator', 'password' => getenv('TEST_MIGRATION_DB_PASSWORD')]]);

        return DB::connection('brand_fixture');
    }
}
