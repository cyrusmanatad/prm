<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/professional-hub.sql')));
        } else {
            Schema::table('users', fn ($table) => $table->string('registration_workspace_experience')->nullable());
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('users', fn ($table) => $table->dropColumn('registration_workspace_experience'));

            return;
        }
        DB::unprepared('DROP FUNCTION IF EXISTS app_public_profile_experience(uuid); DROP TRIGGER professional_assignment_reset ON pilot_assignments; DROP FUNCTION app_professional_assignment_reset();
            DROP FUNCTION app_professional_write_guard() CASCADE;
            DROP FUNCTION app_schedule_basis(business_schedules);
            ALTER FUNCTION app_schedule_basis_before_professional(business_schedules) RENAME TO app_schedule_basis;
            DROP FUNCTION app_professional_active(uuid);
            DROP FUNCTION app_create_experience_workspace(uuid,text,text,text);
            DROP FUNCTION app_workspace_creation_experience() CASCADE;
            DROP FUNCTION app_preserve_workspace_experience() CASCADE;
            ALTER TABLE organizations DROP COLUMN experience;
            ALTER TABLE users DROP COLUMN registration_workspace_experience;');
    }
};
