<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/public-profile.sql')));
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Public profiles require PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
DROP FUNCTION app_public_kit(uuid);
ALTER TABLE media_kits DROP COLUMN social_links;
CREATE FUNCTION app_public_kit(target uuid) RETURNS TABLE(title varchar,bio text,website varchar)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
    UPDATE public.media_kits k SET views=k.views+1 WHERE k.id=target AND k.published AND k.consented_at IS NOT NULL;
    RETURN QUERY SELECT k.title,k.bio,k.website FROM public.media_kits k WHERE k.id=target AND k.published AND k.consented_at IS NOT NULL;
END $$;
REVOKE ALL ON FUNCTION app_public_kit(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_public_kit(uuid) TO weavdash_app;
SQL);
    }
};
