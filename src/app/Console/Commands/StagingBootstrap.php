<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StagingBootstrap extends Command
{
    protected $signature = 'staging:bootstrap {credentials : Private JSON file with partner, brand and professional passwords}';

    protected $description = 'Create synthetic staging reviewers once, without modifying existing accounts';

    public function handle(): int
    {
        if (! config('staging.enabled') || DB::connection()->getDriverName() !== 'pgsql' || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator') {
            $this->error('Requires staging mode and the migration database role.');

            return self::FAILURE;
        }
        $credentials = json_decode(file_get_contents($this->argument('credentials')), true, flags: JSON_THROW_ON_ERROR);
        $passwords = array_map(fn ($role) => $credentials[$role] ?? null, ['partner', 'brand', 'professional']);
        if (count(array_unique($passwords)) !== 3 || array_filter($passwords, fn ($password) => ! is_string($password) || strlen($password) < 20)) {
            $this->error('Supply three distinct passwords, each at least 20 characters.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($credentials): void {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('staging:bootstrap', 0))");
            foreach (['partner', 'brand', 'professional'] as $role) {
                $email = $role.'@preview.example.test';
                if (User::where('email', $email)->exists()) {
                    $this->info($role.': existing account preserved');

                    continue;
                }
                $user = new User(['name' => ucfirst($role).' Reviewer', 'email' => $email, 'password' => $credentials[$role]]);
                $user->email_verified_at = now();
                $user->save();
                $org = (string) Str::uuid();
                DB::table('organizations')->insert(['id' => $org, 'name' => ucfirst($role).' Staging', 'kind' => $role === 'brand' ? 'brand' : 'creator', 'experience' => $role === 'professional' ? 'professional' : 'partner']);
                DB::table('memberships')->insert(['org_id' => $org, 'user_id' => $user->id, 'role' => 'owner']);
                $plan = (string) Str::uuid();
                DB::table('pilot_plan_versions')->insert(['id' => $plan, 'name' => 'Synthetic staging '.$role, 'version' => 1,
                    'quotas' => json_encode(array_fill_keys(['pipeline_entries', 'brand_contacts', 'reminders', 'onboarding_requests', 'outreach_templates', 'invoice_drafts', 'manual_payments'], 100))]);
                DB::table('pilot_assignments')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'plan_id' => $plan, 'version' => 1, 'starts_at' => now(), 'ends_at' => now()->addDays(90)]);
                DB::table('pilot_admin_events')->insert(['id' => (string) Str::uuid(), 'operator' => 'staging:bootstrap', 'reason' => 'Synthetic colleague review; no billing', 'event' => 'pilot.assigned', 'subject_id' => $org, 'org_id' => $org]);
                if ($role !== 'brand') {
                    foreach (['Example Studio', 'Sample Workshop', 'Demo Collective'] as $index => $name) {
                        DB::table('pipeline_entries')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'brand_name' => $name,
                            'contact_name' => 'Synthetic Contact', 'contact_email' => 'contact'.$index.'@example.test', 'currency' => 'USD', 'value_minor' => ($index + 1) * 10000,
                            'stage' => ['lead', 'pitched', 'negotiating'][$index], 'notes' => 'Synthetic staging record. Safe to edit.']);
                    }
                }
                DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'actor_id' => $user->id, 'event' => 'staging.bootstrapped', 'subject_id' => $org]);
                $this->info($role.': created verified reviewer and 90-day demonstration subscription');
            }
        });

        return self::SUCCESS;
    }
}
