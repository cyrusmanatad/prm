<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Compact administration Assignments and Support access: chip counts. Both functions keep their signatures and fields.
 */
return new class extends Migration
{
    /** Functions this migration replaced, with the file that holds each earlier definition. */
    private const REPLACED = [
        'app_platform_assignments' => 'platform-console.sql',
        'app_platform_support_list' => 'platform-governance.sql',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/platform-compact-entitlements.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('The administration console requires PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // Restore each replaced function exactly as its earlier migration defined it.
        foreach (self::REPLACED as $name => $file) {
            preg_match_all('/CREATE (?:OR REPLACE )?FUNCTION '.$name.'\(.*?END \$\$;/s', file_get_contents(database_path('sql/'.$file)), $matches);
            $definition = end($matches[0]);
            if (! $definition) {
                throw new RuntimeException('Earlier definition of '.$name.' not found in '.$file.'.');
            }
            DB::unprepared(preg_replace('/^CREATE (?:OR REPLACE )?FUNCTION/', 'CREATE OR REPLACE FUNCTION', $definition));
        }
    }
};
