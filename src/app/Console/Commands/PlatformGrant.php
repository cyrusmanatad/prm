<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Offline operator procedure. Must run with an explicitly supplied migration connection. */
class PlatformGrant extends Command
{
    protected $signature = 'platform:grant {user : Existing verified user ID} {--permission=* : platform.owner, directory.read, plans.assign, notifications.read or notifications.reconcile} {--reason=} {--operator= : Accountable offline operator label} {--recover : Reset authenticator and invalidate sessions} {--revoke : Revoke this grant}';

    protected $description = 'Bootstrap, recover or revoke an explicit platform grant using offline migration credentials';

    public function handle(): int
    {
        $role = DB::selectOne('SELECT current_user AS name')->name;
        if ($role !== 'weavdash_migrator' || ! is_string($this->option('operator')) || strlen(trim($this->option('operator'))) < 1 || strlen($this->option('operator')) > 160 || ! ctype_digit((string) $this->argument('user')) || ! is_string($this->option('reason')) || strlen(trim($this->option('reason'))) < 1 || strlen($this->option('reason')) > 240) {
            $this->error('Explicit migration role, operator label, verified user ID and reason (1–240 characters) required.');

            return self::FAILURE;
        }
        $permissions = $this->option('permission');
        if (array_diff($permissions, ['platform.owner', 'directory.read', 'plans.assign', 'notifications.read', 'notifications.reconcile']) || (! $permissions && ! $this->option('recover') && ! $this->option('revoke')) || ($this->option('recover') && $this->option('revoke'))) {
            $this->error('Supply explicit permissions or exactly one recovery/revocation action.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($permissions): void {
            DB::statement('LOCK TABLE platform_grants IN EXCLUSIVE MODE');
            $id = (int) $this->argument('user');
            $user = DB::table('users')->where('id', $id)->whereNotNull('email_verified_at')->lockForUpdate()->first();
            abort_unless($user, 422, 'Existing verified identity required.');
            $old = DB::table('platform_grants')->where('user_id', $id)->first();
            abort_if(($this->option('recover') || $this->option('revoke')) && ! $old, 409, 'An existing grant is required.');
            $otherOwners = DB::table('platform_grants as g')->join('users as u', 'u.id', '=', 'g.user_id')->where('g.user_id', '<>', $id)->whereNull('g.revoked_at')->whereNotNull('u.email_verified_at')->whereNotNull('u.admin_totp_confirmed_at')->whereRaw("'platform.owner'=ANY(g.permissions)")->exists();
            $removing = $this->option('revoke') || ($permissions && ! in_array('platform.owner', $permissions, true));
            if ($old && str_contains($old->permissions, 'platform.owner') && $removing) {
                abort_unless($otherOwners, 409, 'Cannot remove the last usable platform owner. Enroll another owner first.');
            }
            if ($this->option('recover')) {
                abort_unless($old && ! $old->revoked_at, 409, 'Recovery requires an active grant.');
                DB::table('users')->where('id', $id)->update(['admin_totp_secret' => null, 'admin_totp_confirmed_at' => null, 'admin_totp_counter' => null]);
            }
            DB::table('platform_grants')->updateOrInsert(['user_id' => $id], ['permissions' => $permissions ? '{'.implode(',', $permissions).'}' : $old->permissions,
                'version' => ($old->version ?? 0) + 1, 'revoked_at' => $this->option('revoke') ? now() : null]);
            DB::table('sessions')->where('user_id', $id)->delete();
            DB::table('platform_audit')->insert(['actor_id' => null, 'operator' => trim($this->option('operator')), 'event' => $this->option('recover') ? 'grant.recovered.offline' : ($this->option('revoke') ? 'grant.revoked.offline' : 'grant.assigned.offline'), 'target' => (string) $id, 'reason' => trim($this->option('reason'))]);
        });
        $this->info('Grant recorded. Sign in and enroll/verify an authenticator at /admin/security.');

        return self::SUCCESS;
    }
}
