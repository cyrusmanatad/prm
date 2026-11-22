<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Compact administration lists: chip counts for Workspaces, Notifications and Audit, and one shared helper
 * that names audit targets on the Overview and the Audit list. Every function keeps its signature and fields.
 */
return new class extends Migration
{
    /** Functions this migration replaced, with the file that holds each earlier definition. */
    private const REPLACED = [
        'app_platform_workspaces' => 'platform-followup.sql',
        'app_platform_notification_list' => 'brand-approval.sql',
        'app_platform_audit_list' => 'platform-console.sql',
        'app_platform_overview' => 'platform-compact-console.sql',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/platform-compact-lists.sql')));
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
        DB::unprepared('DROP FUNCTION app_platform_audit_target(text,text,boolean);');
    }
};
