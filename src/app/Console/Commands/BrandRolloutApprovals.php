<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Offline rollout step for Brand workspace approval (D37). Records an explicit rollout approval for each
 * Brand workspace that predates review. It changes no workspace, membership, subscription, invitation or
 * partnership, and sends no email. Must run with an explicitly supplied migration connection.
 */
class BrandRolloutApprovals extends Command
{
    protected $signature = 'brand:rollout-approvals {--approver= : Account ID of the approving platform owner} {--approver-email= : Or that account\'s sign-in email}
        {--reason= : Why existing Brand workspaces are approved (1–240 characters)} {--operator= : Accountable offline operator label} {--dry-run : List what would be recorded and write nothing}';

    protected $description = 'Record rollout approvals for existing Brand workspaces that have no approval provenance';

    public function handle(): int
    {
        $operator = $this->option('operator');
        $reason = $this->option('reason');
        if (DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator' || ! is_string($operator) || strlen(trim($operator)) < 1 || strlen($operator) > 160
            || ! is_string($reason) || strlen(trim($reason)) < 1 || strlen($reason) > 240) {
            $this->error('Explicit migration role, operator label and rollout reason (1–240 characters) required.');

            return self::FAILURE;
        }
        $id = $this->option('approver');
        $email = $this->option('approver-email');
        if (($id === null) === ($email === null) || ($id !== null && ! ctype_digit((string) $id))) {
            $this->error('Supply exactly one of --approver (account ID) or --approver-email.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');

        return DB::transaction(function () use ($id, $email, $operator, $reason, $dryRun): int {
            // Serializes concurrent runs; a repeated run finds nothing left to record.
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('brand:rollout-approvals', 0))");
            $approver = DB::table('users')->when($id !== null, fn ($query) => $query->where('id', (int) $id), fn ($query) => $query->whereRaw('lower(email)=lower(?)', [$email]))->sharedLock()->first();
            // The approver is an existing account checked here, never an identity built into the application.
            $usable = $approver && $approver->email_verified_at !== null
                && ! DB::table('platform_suspensions')->where('user_id', $approver->id)->whereNull('lifted_at')->exists()
                && DB::table('platform_grants')->where('user_id', $approver->id)->whereNull('revoked_at')->whereRaw("'platform.owner'=ANY(permissions)")->exists();
            if (! $usable) {
                $this->error('The approving account must be an existing, verified, unsuspended platform owner. Nothing was written.');

                return self::FAILURE;
            }
            $brands = DB::table('organizations as o')->where('o.kind', 'brand')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('brand_requests as r')->whereColumn('r.org_id', 'o.id')->where('r.status', 'approved'))
                ->orderBy('o.created_at')->orderBy('o.id')->sharedLock()->get(['o.id', 'o.name', 'o.created_at']);
            $recorded = DB::table('organizations')->where('kind', 'brand')->count() - $brands->count();
            $executedAt = DB::selectOne('SELECT clock_timestamp() AS at')->at;
            if (! $dryRun) {
                foreach ($brands as $brand) {
                    $request = (string) Str::uuid();
                    DB::table('brand_requests')->insert(['id' => $request, 'source' => 'rollout', 'status' => 'approved', 'org_id' => $brand->id,
                        'submitted_at' => $executedAt, 'updated_at' => $executedAt, 'decided_at' => $executedAt]);
                    DB::table('brand_request_events')->insert(['id' => (string) Str::uuid(), 'request_id' => $request, 'version' => 1, 'event' => 'rollout_approved',
                        'actor_id' => $approver->id, 'operator' => trim($operator), 'reason' => trim($reason), 'occurred_at' => $executedAt]);
                    DB::table('platform_audit')->insert(['actor_id' => null, 'operator' => trim($operator), 'event' => 'brand.rollout_approved', 'target' => $brand->id,
                        'reason' => trim($reason), 'occurred_at' => $executedAt]);
                }
            }
            $this->table(['Workspace ID', 'Name', 'Created'], $brands->map(fn ($brand) => [$brand->id, $brand->name, $brand->created_at])->all());
            // One machine-readable line for the rollout record. No email address is printed.
            $this->line(json_encode(['dry_run' => $dryRun, 'approver_id' => (int) $approver->id, 'operator' => trim($operator), 'executed_at' => $executedAt,
                'brand_workspaces' => $recorded + $brands->count(), 'already_recorded' => $recorded, $dryRun ? 'would_record' : 'recorded' => $brands->count(),
                'workspace_ids' => $brands->pluck('id')->all()], JSON_THROW_ON_ERROR));
            $this->info($dryRun ? 'Dry run: nothing was written.' : 'Rollout approvals recorded. No email was sent.');

            return self::SUCCESS;
        });
    }
}
