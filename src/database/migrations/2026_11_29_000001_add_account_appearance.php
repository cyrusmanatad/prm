<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Personal accent color. Every existing and new account defaults to Burgundy; only the two offered values are stored. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // A constant default adds the column without rewriting existing rows.
            DB::statement("ALTER TABLE users ADD COLUMN appearance varchar(16) NOT NULL DEFAULT 'burgundy'
                CONSTRAINT users_appearance_check CHECK (appearance IN ('burgundy','purple'))");
        } else {
            Schema::table('users', fn ($table) => $table->string('appearance', 16)->default('burgundy'));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP COLUMN appearance');
        } else {
            Schema::table('users', fn ($table) => $table->dropColumn('appearance'));
        }
    }
};
