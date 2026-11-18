<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Migration-role writer for the staging demonstration registrations (D38). It registers the explicitly listed
 * presentation leads with the presentation Brand and nothing else. Every source and target is named by ID in
 * the versioned manifest and checked against the live rows first; nothing is matched by company name alone.
 */
class PresentationRegistrations
{
    public const VERSION = 'northline-registrations-v1';

    public function manifest(): array
    {
        return json_decode(file_get_contents($this->path(self::VERSION)), true, flags: JSON_THROW_ON_ERROR);
    }

    private function path(string $version): string
    {
        return database_path('fixtures/'.$version.'.json');
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    public function provision(bool $dryRun = false): array
    {
        $manifest = $this->manifest();
        $proof = self::VERSION.' sha256:'.hash_file('sha256', $this->path(self::VERSION));
        $rows = $manifest['registrations'];
        $this->require($manifest['version'] === self::VERSION && count($rows) === 13, 'Invalid registration manifest.');
        foreach (['partner', 'partnership_id', 'creator_org_id', 'pipeline_entry_id', 'registration_id'] as $field) {
            $this->require(count(array_unique(array_column($rows, $field))) === 13, 'Registration manifest repeats '.$field.'.');
        }
        // The manifest belongs to one exact presentation dataset file.
        $this->require($manifest['dataset']['version'] === PresentationDataset::VERSION
            && hash_file('sha256', $this->path(PresentationDataset::VERSION)) === $manifest['dataset']['sha256'], 'Presentation dataset file does not match this manifest.');

        return DB::transaction(function () use ($manifest, $proof, $rows, $dryRun): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['staging:seed-presentation:'.self::VERSION]);
            $dataset = DB::table('fixture_batches')->where('id', $manifest['dataset']['batch_id'])->first();
            $this->require($dataset !== null && $dataset->name === PresentationDataset::VERSION
                && $dataset->reason === PresentationDataset::VERSION.' sha256:'.$manifest['dataset']['sha256'], 'The presentation dataset is not provisioned from the expected manifest.');
            $brand = $manifest['brand']['org_id'];
            $this->require(DB::table('organizations')->where(['id' => $brand, 'kind' => 'brand', 'name' => $manifest['brand']['name']])->exists()
                && DB::table('fixture_marks')->where(['batch_id' => $dataset->id, 'subject' => 'workspace', 'org_id' => $brand])->exists(), 'Brand workspace is not the presentation Brand.');
            $batch = DB::table('fixture_batches')->where('id', $manifest['batch_id'])->first();
            $this->require($batch === null || ($batch->name === self::VERSION && $batch->reason === $proof), 'Registration batch ownership or manifest mismatch.');
            $this->require($batch !== null || ! DB::table('fixture_batches')->where('name', self::VERSION)->exists(), 'Conflicting fixture batch.');
            $plan = [];
            foreach ($rows as $row) {
                // Sources and targets must be presentation records, still connected, and still the listed lead.
                $this->require(DB::table('fixture_marks')->where(['batch_id' => $dataset->id, 'subject' => 'workspace', 'org_id' => $row['creator_org_id']])->exists(), 'Not a presentation Partner workspace: '.$row['partner']);
                $this->require(DB::table('partnerships')->where(['id' => $row['partnership_id'], 'brand_org_id' => $brand, 'creator_org_id' => $row['creator_org_id'], 'status' => 'active'])->exists(), 'Partnership is not active for '.$row['partner']);
                $entry = DB::table('pipeline_entries')->where(['id' => $row['pipeline_entry_id'], 'org_id' => $row['creator_org_id']])->lockForUpdate()->first();
                $this->require($entry !== null && $entry->brand_name === $manifest['source_company'], 'Source lead is missing or no longer the listed lead for '.$row['partner']);
                $owner = DB::table('memberships')->where(['org_id' => $row['creator_org_id'], 'role' => 'owner'])->whereNull('revoked_at')->orderBy('user_id')->value('user_id');
                $this->require($owner !== null && DB::table('fixture_marks')->where(['batch_id' => $dataset->id, 'subject' => 'account', 'user_id' => $owner])->exists(), 'Partner owner is not a presentation account: '.$row['partner']);
                $plan[] = [$row, $entry, (int) $owner];
            }
            $stored = DB::table('lead_registrations')->whereIn('id', array_column($rows, 'registration_id'))->get()->keyBy('id');
            // Registrations made by people through the product are never adopted, replaced or duplicated.
            $foreign = DB::table('lead_registrations')->whereIn('partnership_id', array_column($rows, 'partnership_id'))
                ->whereIn('pipeline_entry_id', array_column($rows, 'pipeline_entry_id'))->whereNotIn('id', array_column($rows, 'registration_id'))->count();
            $this->require($foreign === 0, 'A listed lead was already registered outside this manifest; nothing was changed.');
            $this->require($batch === null ? $stored->isEmpty() : $stored->count() === 13, 'Incomplete or conflicting demonstration registrations.');
            foreach ($plan as [$row]) {
                $existing = $stored->get($row['registration_id']);
                $this->require($existing === null || ($existing->partnership_id === $row['partnership_id'] && $existing->creator_org_id === $row['creator_org_id'] && $existing->brand_org_id === $brand), 'Registration ownership mismatch for '.$row['partner']);
            }
            $summary = ['manifest' => $proof, 'dataset' => $dataset->reason, 'action' => $batch !== null ? 'preserve' : ($dryRun ? 'would_create' : 'created'), 'dry_run' => $dryRun,
                'brand' => $manifest['brand']['name'], 'registrations' => count($plan), 'private_leads_left_private' => DB::table('pipeline_entries')
                    ->whereIn('org_id', array_column($rows, 'creator_org_id'))->whereNotIn('id', array_column($rows, 'pipeline_entry_id'))->count(),
                'rows' => array_map(fn ($item) => ['partner' => $item[0]['partner_name'], 'lead_name' => $item[0]['display_name'], 'stage' => $item[1]->stage, 'shares_contact' => $item[0]['share_contact']], $plan)];
            if ($dryRun || $batch !== null) {
                return $summary;
            }
            // The registration time is the actual time of this run; nothing is backdated.
            $at = now()->utc();
            foreach ($plan as [$row, $entry, $owner]) {
                DB::table('lead_registrations')->insert(['id' => $row['registration_id'], 'partnership_id' => $row['partnership_id'], 'brand_org_id' => $brand,
                    'creator_org_id' => $row['creator_org_id'], 'pipeline_entry_id' => $row['pipeline_entry_id'], 'display_name' => $row['display_name'], 'summary' => $row['summary'],
                    'share_contact' => false, 'stage' => $entry->stage, 'registered_by' => $owner, 'registered_at' => $at, 'shared_updated_at' => $at]);
                DB::table('audit_logs')->insert(['id' => DB::selectOne('SELECT gen_random_uuid() AS id')->id, 'org_id' => $row['creator_org_id'], 'actor_id' => $owner,
                    'event' => 'staging.presentation_registration', 'subject_id' => $row['registration_id'], 'occurred_at' => $at]);
            }
            DB::table('fixture_batches')->insert(['id' => $manifest['batch_id'], 'name' => self::VERSION, 'reason' => $proof, 'created_by' => $dataset->created_by]);

            return $summary;
        });
    }
}
