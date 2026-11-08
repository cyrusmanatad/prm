<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ProfessionalAccess
{
    public function active(string $org): bool
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['pilot:'.$org]);

        return (bool) DB::selectOne('SELECT app_professional_active(?::uuid) AS active', [$org])->active;
    }

    public function requireActive(string $org): void
    {
        abort_unless($this->active($org), 403, 'Work is paused. Saved records remain readable and exportable.');
    }
}
