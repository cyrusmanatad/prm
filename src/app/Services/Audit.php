<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Audit
{
    public function record(int $actorId, string $orgId, string $event, string $subjectId): void
    {
        // Only identifiers and event names: no passwords, emails, tokens, or form contents.
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'org_id' => $orgId, 'actor_id' => $actorId,
            'event' => $event, 'subject_id' => $subjectId,
        ]);
    }
}
