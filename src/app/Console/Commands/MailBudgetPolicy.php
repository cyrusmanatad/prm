<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class MailBudgetPolicy extends Command
{
    protected $signature = 'mail:budget-policy {--enable} {--disable} {--operator=} {--reason=}';

    protected $description = 'Inspect or explicitly activate the approved pilot mail budget with the migration role';

    public function handle(): int
    {
        try {
            if (DB::getDriverName() !== 'pgsql' || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator') {
                throw new RuntimeException('Requires PostgreSQL and the migration database role.');
            }
            if ($this->option('enable') && $this->option('disable')) {
                throw new RuntimeException('Choose either enable or disable.');
            }
            if ($this->option('enable') || $this->option('disable')) {
                $operator = trim((string) $this->option('operator'));
                $reason = trim((string) $this->option('reason'));
                if ($operator === '' || mb_strlen($operator) > 160 || $reason === '' || mb_strlen($reason) > 240) {
                    throw new RuntimeException('Provide a nonempty operator (up to 160 characters) and reason (up to 240 characters).');
                }
                DB::transaction(function () use ($operator, $reason): void {
                    $policy = DB::table('mail_budget_policy')->where('singleton', true)->lockForUpdate()->first();
                    $enabled = (bool) $this->option('enable');
                    if ($policy->enabled !== $enabled) {
                        DB::table('mail_budget_policy')->where('singleton', true)->update(['enabled' => $enabled]);
                        DB::table('platform_audit')->insert(['operator' => $operator, 'event' => $enabled ? 'mail.budget.enabled' : 'mail.budget.disabled',
                            'target' => 'provider-mail-budget', 'reason' => $reason]);
                    }
                });
            }
            $this->line(DB::selectOne('SELECT app_mail_budget_snapshot() AS data')->data);

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Mail budget operation failed. Check the database role, migration and command options; no secrets are printed.');

            return self::FAILURE;
        }
    }
}
