<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PilotPlan extends Command
{
    protected $signature = 'pilot:plan {name} {version} {quotas : JSON object of explicit resource limits} {--operator=} {--reason=}';

    protected $description = 'Create an immutable local pilot plan version (migration credentials only)';

    public function handle(): int
    {
        PilotAssign::guard($this);
        $data = validator(['name' => $this->argument('name'), 'version' => $this->argument('version')], ['name' => 'required|string|max:120', 'version' => 'required|integer|min:1|max:2147483647'])->validate();
        $quotas = json_decode($this->argument('quotas'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($quotas) || ! str_starts_with(trim($this->argument('quotas')), '{')) {
            throw new \InvalidArgumentException('Quotas must be a JSON object.');
        }
        foreach ($quotas as $resource => $limit) {
            if (! in_array($resource, ['pipeline_entries', 'brand_contacts', 'reminders', 'onboarding_requests', 'outreach_templates', 'invoice_drafts', 'manual_payments'], true)
                || ! is_int($limit) || $limit < 0 || $limit > 1000000000) {
                throw new \InvalidArgumentException('Use supported resource keys and integer limits from 0 to 1000000000.');
            }
        }
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $data, $quotas): void {
            DB::table('pilot_plan_versions')->insert([...$data, 'id' => $id, 'quotas' => json_encode((object) $quotas, JSON_THROW_ON_ERROR)]);
            PilotAssign::audit($this, 'pilot.plan_created', $id, null);
        });
        $this->line($id);

        return self::SUCCESS;
    }
}
