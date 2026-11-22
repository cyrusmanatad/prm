<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Compact administration Overview and Accounts: account chip counts, and the kind and name of each
 * recent action's target. Both functions keep their signatures and existing fields.
 */
return new class extends Migration
{
    /** Functions this migration replaced, with the file that holds each earlier definition. */
    private const REPLACED = [
        'app_platform_accounts' => 'platform-followup.sql',
        'app_platform_overview' => 'brand-approval.sql',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/platform-compact-console.sql')));
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
