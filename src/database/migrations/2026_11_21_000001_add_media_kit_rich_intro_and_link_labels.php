<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The public introduction stores sanitized HTML, so its stored length allows markup while the
 * application limits visible text to 5,000 characters. Lead links gain an optional private label.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            if (! app()->environment('testing')) {
                throw new RuntimeException('Media kits require PostgreSQL.');
            }

            return;
        }
        DB::unprepared('ALTER TABLE media_kits DROP CONSTRAINT media_kits_bio_check, ADD CONSTRAINT media_kits_bio_check CHECK(length(bio)<=20000);
            ALTER TABLE lead_links ADD COLUMN label varchar(80) NOT NULL DEFAULT \'\';
            CREATE INDEX lead_links_org_created ON lead_links(org_id,created_at DESC,id DESC);');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('media_kits')->whereRaw('length(bio)>5000')->exists()) {
            throw new RuntimeException('Rollback refuses to truncate formatted introductions longer than 5,000 characters. Apply a forward fix.');
        }
        DB::unprepared('DROP INDEX lead_links_org_created; ALTER TABLE lead_links DROP COLUMN label;
            ALTER TABLE media_kits DROP CONSTRAINT media_kits_bio_check, ADD CONSTRAINT media_kits_bio_check CHECK(length(bio)<=5000);');
    }
};
