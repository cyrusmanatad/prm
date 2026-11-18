<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/** Migration-role fixture writer. Deliberately bypasses event/email-producing product flows. */
class PresentationDataset
{
    public const VERSION = 'northline-living-v1';

    public const RESOURCES = ['pipeline_entries', 'brand_contacts', 'reminders', 'onboarding_requests', 'outreach_templates', 'invoice_drafts', 'manual_payments'];

    public function manifest(): array
    {
        return json_decode(file_get_contents(database_path('fixtures/'.self::VERSION.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function id(string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://weavdash.example.test/presentation/'.self::VERSION.'/'.$key)->toString();
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    private function rosterPath(string $path): string
    {
        $directory = realpath(dirname($path));
        $this->require(str_starts_with($path, '/') && $directory !== false && is_writable($directory), 'Roster requires an existing writable absolute private directory.');
        $resolved = $directory.'/'.basename($path);
        foreach ([base_path(), dirname(base_path())] as $root) {
            $this->require(! str_starts_with($resolved, realpath($root).'/'), 'Roster must be outside the application and Git checkout.');
        }
        $this->require((fileperms($directory) & 0077) === 0, 'Roster directory must be private (mode 700).');
        $this->require(! is_link($path), 'Roster cannot be a symbolic link.');

        return $resolved;
    }

    public function provision(string $path, bool $dryRun = false): array
    {
        $path = $this->rosterPath($path);
        $manifest = $this->manifest();
        $this->require($manifest['version'] === self::VERSION, 'Dataset version mismatch.');
        $proof = self::VERSION.' sha256:'.hash_file('sha256', database_path('fixtures/'.self::VERSION.'.json'));
        $identities = [$manifest['brand'], ...$manifest['partners']];
        $this->require(count($manifest['partners']) === 13 && array_sum(array_map(fn ($p) => count($p['leads']), $manifest['partners'])) === 273
            && count(array_unique(array_column($identities, 'key'))) === 14 && count(array_unique(array_column($identities, 'email'))) === 14, 'Invalid presentation manifest counts or identities.');
        $createdFile = false;
        $durableRoster = false;
        try {
            return DB::transaction(function () use ($path, $dryRun, $manifest, $proof, $identities, &$createdFile, &$durableRoster): array {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['staging:seed-presentation:'.self::VERSION]);
                $batch = DB::table('fixture_batches')->where('id', $this->id('batch'))->first();
                $existing = $batch !== null;
                $this->require(! $existing || ($batch->name === self::VERSION && $batch->reason === $proof), 'Dataset batch ownership or manifest mismatch.');
                $this->require($existing || ! DB::table('fixture_batches')->where('name', self::VERSION)->exists(), 'Conflicting fixture batch.');
                $users = [];
                foreach ($identities as $identity) {
                    $user = DB::table('users')->whereRaw('lower(email) = ?', [strtolower($identity['email'])])->first();
                    $org = $this->id($identity['key'].'/workspace');
                    if ($existing) {
                        $this->require($user !== null && DB::table('fixture_marks')->where(['batch_id' => $batch->id, 'subject' => 'account', 'user_id' => $user->id, 'evidence' => $proof])->exists()
                            && DB::table('fixture_marks')->where(['batch_id' => $batch->id, 'subject' => 'workspace', 'org_id' => $org, 'evidence' => $proof])->exists()
                            && DB::table('memberships')->where(['org_id' => $org, 'user_id' => $user->id, 'role' => 'owner'])->whereNull('revoked_at')->exists(), 'Incomplete or mismatched dataset ownership: '.$identity['key']);
                        $users[$identity['key']] = $user->id;
                    } else {
                        $this->require($user === null && ! DB::table('organizations')->where('id', $org)->exists(), 'Conflicting account or workspace: '.$identity['key']);
                    }
                }
                if ($existing) {
                    $this->require((int) $batch->created_by === $users[$manifest['brand']['key']]
                        && ! DB::table('fixture_marks')->where('batch_id', $batch->id)->where('marked_by', '<>', $batch->created_by)->exists(), 'Fixture provenance mismatch.');
                    $this->require(DB::table('fixture_marks')->where('batch_id', $batch->id)->count() === 28, 'Incomplete fixture inventory.');
                    $this->require(is_file($path) && (fileperms($path) & 0777) === 0600, 'Existing dataset requires its private mode-600 roster; credentials are never regenerated.');
                    $roster = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                    $this->require(($roster['version'] ?? null) === self::VERSION && ($roster['manifest'] ?? null) === $proof && count($roster['accounts'] ?? []) === 14, 'Roster manifest mismatch.');
                    foreach ($identities as $i => $identity) {
                        $this->require(($roster['accounts'][$i]['email'] ?? null) === $identity['email'] && ($roster['accounts'][$i]['user_id'] ?? null) === $users[$identity['key']]
                            && ($roster['accounts'][$i]['workspace_id'] ?? null) === $this->id($identity['key'].'/workspace'), 'Roster ownership mismatch.');
                    }
                } else {
                    $this->require(! file_exists($path), 'Refusing to overwrite a roster for an unprovisioned dataset.');
                }
                $at = now()->utc();
                // Build every row before writing, to check stable identifiers even during a dry run.
                $rows = $this->rows($manifest, $users, $proof, $at);
                foreach ($rows as $table => $records) {
                    $storedRecords = DB::table($table)->whereIn('id', array_column($records, 'id'))->get()->keyBy('id');
                    foreach ($records as $row) {
                        $stored = $storedRecords->get($row['id']);
                        $this->require($existing ? $stored !== null : $stored === null, 'Incomplete dataset or conflicting identifier in '.$table);
                        if ($existing) {
                            foreach (['org_id', 'pipeline_entry_id', 'actor_id', 'brand_org_id', 'creator_org_id', 'invitation_id', 'request_id', 'plan_id', 'source', 'operator', 'subject_id', 'kind', 'experience'] as $field) {
                                if (array_key_exists($field, $row)) {
                                    $this->require((string) $stored->$field === (string) $row[$field], 'Dataset record ownership mismatch in '.$table);
                                }
                            }
                        }
                    }
                }
                foreach ($manifest['partners'] as $partner) {
                    foreach ($partner['leads'] as $lead) {
                        $link = $this->link($partner['key'], $lead['key']);
                        $this->require(DB::table('pipeline_contacts')->where($link)->exists() === $existing, 'Incomplete or conflicting contact link.');
                    }
                }
                $summary = ['dataset' => self::VERSION, 'manifest' => $proof, 'action' => $existing ? 'preserve' : ($dryRun ? 'would_create' : 'created'), 'dry_run' => $dryRun,
                    'accounts' => 14, 'partners' => 13, 'leads' => 273, 'currency' => 'USD', 'entitlement_days' => 90,
                    'workspaces' => array_map(fn ($i) => ['name' => $i['name'], 'email' => $i['email'], 'id' => $this->id($i['key'].'/workspace'), 'leads' => count($i['leads'] ?? [])], $identities)];
                if ($dryRun || $existing) {
                    return $summary;
                }
                $accounts = [];
                foreach ($identities as $identity) {
                    $password = bin2hex(random_bytes(20));
                    $user = new User(['name' => $identity['person'], 'email' => $identity['email'], 'password' => $password]);
                    $user->email_verified_at = $at;
                    $user->saveQuietly();
                    $users[$identity['key']] = $user->id;
                    $accounts[] = ['name' => $identity['name'], 'email' => $identity['email'], 'password' => $password, 'user_id' => $user->id, 'workspace_id' => $this->id($identity['key'].'/workspace'), 'login' => $identity['key'] === 'northline-living' ? '/brand/login' : '/partner/login'];
                }
                $rows = $this->rows($manifest, $users, $proof, $at);
                foreach ($rows as $table => $records) {
                    foreach ($records as $row) {
                        DB::table($table)->insert($row);
                    }
                }
                $owner = $users[$manifest['brand']['key']];
                DB::table('fixture_batches')->insert(['id' => $this->id('batch'), 'name' => self::VERSION, 'reason' => $proof, 'created_by' => $owner]);
                foreach ($identities as $identity) {
                    $org = $this->id($identity['key'].'/workspace');
                    $user = $users[$identity['key']];
                    DB::table('memberships')->insert(['org_id' => $org, 'user_id' => $user, 'role' => 'owner', 'created_at' => $at]);
                    DB::table('fixture_marks')->insert([
                        ['batch_id' => $this->id('batch'), 'subject' => 'account', 'user_id' => $user, 'org_id' => null, 'evidence' => $proof, 'marked_by' => $owner],
                        ['batch_id' => $this->id('batch'), 'subject' => 'workspace', 'user_id' => null, 'org_id' => $org, 'evidence' => $proof, 'marked_by' => $owner],
                    ]);
                }
                foreach ($manifest['partners'] as $partner) {
                    foreach ($partner['leads'] as $lead) {
                        DB::table('pipeline_contacts')->insert($this->link($partner['key'], $lead['key']));
                    }
                }
                // Exclusive creation protects credentials. Write before commit so IO failure rolls back data.
                $oldMask = umask(0077);
                try {
                    $file = fopen($path, 'x');
                } finally {
                    umask($oldMask);
                }
                $this->require($file !== false, 'Could not exclusively create the private roster.');
                $createdFile = true;
                try {
                    $json = json_encode(['version' => self::VERSION, 'manifest' => $proof, 'seeded_at' => $at->toIso8601String(), 'accounts' => $accounts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
                    $this->require(fwrite($file, $json) === strlen($json) && fflush($file) && fsync($file), 'Could not persist the complete private roster.');
                } finally {
                    fclose($file);
                }
                $durableRoster = true;

                return $summary;
            });
        } catch (Throwable $error) {
            // A lost COMMIT acknowledgment is ambiguous: retain durable credentials for reconciliation.
            if ($createdFile && ! $durableRoster) {
                unlink($path);
            }
            throw $error;
        }
    }

    private function link(string $partner, string $lead): array
    {
        return ['org_id' => $this->id($partner.'/workspace'), 'pipeline_entry_id' => $this->id($partner.'/'.$lead), 'contact_id' => $this->id($partner.'/'.$lead.'/contact')];
    }

    private function rows(array $manifest, array $users, string $proof, $at): array
    {
        $rows = [];
        $add = function (string $table, string $key, array $data) use (&$rows): void {
            $rows[$table][] = ['id' => $this->id($key), ...$data];
        };
        $brand = $this->id('northline-living/workspace');
        foreach ([$manifest['brand'], ...$manifest['partners']] as $identity) {
            $key = $identity['key'];
            $org = $this->id($key.'/workspace');
            $user = $users[$key] ?? 0;
            $add('organizations', $key.'/workspace', ['name' => $identity['name'], 'kind' => $key === 'northline-living' ? 'brand' : 'creator', 'experience' => 'partner', 'created_at' => $at, 'updated_at' => $at]);
        }
        $add('brand_requests', 'approval', ['source' => 'rollout', 'status' => 'approved', 'org_id' => $brand, 'decided_at' => $at, 'submitted_at' => $at, 'updated_at' => $at]);
        $add('brand_request_events', 'approval/event', ['request_id' => $this->id('approval'), 'version' => 1, 'event' => 'rollout_approved', 'operator' => 'staging:seed-presentation', 'reason' => 'Presentation provisioning; invented Brand, not a historical administrator review. '.$proof, 'occurred_at' => $at]);
        $add('pilot_plan_versions', 'plan', ['name' => 'Northline presentation demonstration', 'version' => 1, 'quotas' => json_encode(array_fill_keys(self::RESOURCES, 100))]);
        foreach ([$manifest['brand'], ...$manifest['partners']] as $identity) {
            $key = $identity['key'];
            $org = $this->id($key.'/workspace');
            $user = $users[$key] ?? 0;
            $add('pilot_assignments', $key.'/assignment', ['org_id' => $org, 'plan_id' => $this->id('plan'), 'version' => 1, 'starts_at' => $at, 'ends_at' => $at->copy()->addDays(90)]);
            $add('pilot_admin_events', $key.'/entitlement-event', ['org_id' => $org, 'subject_id' => $org, 'operator' => 'staging:seed-presentation', 'reason' => 'Invented presentation data; no billing. '.$proof, 'event' => 'pilot.assigned']);
            $add('audit_logs', $key.'/audit', ['org_id' => $org, 'actor_id' => $user, 'event' => 'staging.presentation_provisioned', 'subject_id' => $org, 'occurred_at' => $at]);
        }
        foreach ($manifest['partners'] as $partner) {
            $key = $partner['key'];
            $org = $this->id($key.'/workspace');
            $user = $users[$key] ?? 0;
            $add('partner_invitations', $key.'/invitation', ['brand_org_id' => $brand, 'recipient_email' => $partner['email'], 'token_hash' => hash('sha256', $this->id($key.'/consumed-token')), 'status' => 'accepted', 'created_at' => $at, 'expires_at' => $at->copy()->addDays(7)]);
            $add('partnerships', $key.'/partnership', ['brand_org_id' => $brand, 'creator_org_id' => $org, 'brand_name' => $manifest['brand']['name'], 'creator_name' => $partner['name'], 'invitation_id' => $this->id($key.'/invitation'), 'status' => 'active', 'accepted_at' => $at]);
            foreach ($partner['leads'] as $lead) {
                $record = $key.'/'.$lead['key'];
                $created = $at->copy()->subDays($lead['created_days_ago']);
                $activity = $at->copy()->subDays($lead['activity_days_ago']);
                $note = 'Invented presentation data. '.$lead['campaign'].'. Proposal: a styled photo story, two short videos and a usage-rights discussion. No actual commercial activity.';
                $add('pipeline_entries', $record, ['org_id' => $org, 'brand_name' => $lead['company'], 'contact_name' => $lead['contact'], 'contact_email' => $lead['email'], 'notes' => $note, 'stage' => $lead['stage'], 'value_minor' => $lead['value_minor'], 'currency' => 'USD', 'next_action_due' => $lead['completed'] ? null : $at->copy()->addDays($lead['due_days']), 'created_at' => $created, 'updated_at' => $activity]);
                $add('brand_contacts', $record.'/contact', ['org_id' => $org, 'name' => $lead['contact'], 'email' => $lead['email'], 'company' => $lead['company'], 'notes' => $note, 'created_at' => $created, 'updated_at' => $activity]);
                $add('reminders', $record.'/reminder', ['org_id' => $org, 'pipeline_entry_id' => $this->id($record), 'title' => 'Review '.$lead['company'].' '.$partner['focus'].' brief', 'due_at' => $at->copy()->addDays($lead['due_days']), 'completed_at' => $lead['completed'] ? $at->copy()->subDay() : null, 'created_at' => $created, 'updated_at' => $activity]);
                $add('pipeline_activities', $record.'/created', ['org_id' => $org, 'pipeline_entry_id' => $this->id($record), 'actor_id' => $user, 'kind' => 'created', 'body' => 'Invented presentation data: prospect captured for '.$lead['campaign'].'.', 'occurred_at' => $created]);
                $add('pipeline_activities', $record.'/activity', ['org_id' => $org, 'pipeline_entry_id' => $this->id($record), 'actor_id' => $user, 'kind' => 'note', 'body' => 'Invented presentation data: reviewed the creative brief and estimated production scope; current stage '.$lead['stage'].'.', 'occurred_at' => $activity]);
            }
        }

        return $rows;
    }
}
