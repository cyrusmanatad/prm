<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/monitoring.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Monitoring requires PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // Alert state and the worker observation are operational and can be rebuilt; nothing here is a record.
        DB::unprepared("DROP FUNCTION app_monitor_record(text,boolean,bigint,integer,integer),app_monitor_snapshot();
            DROP TABLE monitor_checks;
            DELETE FROM platform_observations WHERE source='worker';
            ALTER TABLE platform_observations DROP CONSTRAINT platform_observations_source_check;
            ALTER TABLE platform_observations ADD CONSTRAINT platform_observations_source_check CHECK(source IN ('scheduler'));
            CREATE OR REPLACE FUNCTION app_record_observation(origin text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS \$\$
            BEGIN
             IF origin IS DISTINCT FROM 'scheduler' THEN RAISE EXCEPTION 'Unknown observation source' USING ERRCODE='22000'; END IF;
             INSERT INTO public.platform_observations(source,observed_at) VALUES(origin,clock_timestamp())
             ON CONFLICT(source) DO UPDATE SET observed_at=excluded.observed_at;
            END \$\$;");
    }
};
