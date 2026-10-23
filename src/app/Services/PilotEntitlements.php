<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PilotEntitlements
{
    public function state(string $org): array
    {
        $assignment = DB::table('pilot_assignments')->where('org_id', $org)->orderByDesc('version')->first();
        if (! $assignment) {
            return ['state' => 'unassigned-development', 'plan' => null, 'usage' => []];
        }
        $plan = DB::table('pilot_plan_versions')->where('id', $assignment->plan_id)->first();
        $quotas = json_decode($plan->quotas, true, flags: JSON_THROW_ON_ERROR);
        $usage = [];
        foreach (['pipeline_entries', 'brand_contacts', 'reminders', 'onboarding_requests', 'outreach_templates', 'invoice_drafts', 'manual_payments'] as $resource) {
            $usage[] = ['resource' => $resource, 'limit' => $quotas[$resource] ?? 0, 'used' => DB::table($resource)->where('org_id', $org)->count()];
        }

        return ['state' => Carbon::parse($assignment->starts_at)->isFuture() ? 'scheduled' : (Carbon::parse($assignment->ends_at)->isPast() ? 'expired' : 'active'),
            'plan' => ['id' => $plan->id, 'name' => $plan->name, 'version' => $plan->version, 'assignment_version' => $assignment->version,
                'starts_at' => $assignment->starts_at, 'ends_at' => $assignment->ends_at], 'usage' => $usage];
    }
}
