<?php

namespace App\Console\Commands;

use App\Services\OnboardingStorageTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class OnboardingStorage extends Command
{
    protected $signature = 'onboarding:storage {action : inventory, copy or verify}
        {--from=onboarding-local} {--to=onboarding-r2}
        {--writers-paused : Confirm web, app, worker and scheduler writers are stopped}';

    protected $description = 'Inventory or verify/copy all referenced staging attachments using the migration role';

    public function handle(OnboardingStorageTransfer $transfer): int
    {
        if (! config('staging.enabled') || DB::connection()->getDriverName() !== 'pgsql' || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator') {
            $this->error('Requires staging mode and the migration database role (all tenant rows).');

            return self::FAILURE;
        }
        $action = $this->argument('action');
        $from = $this->option('from');
        $to = $this->option('to');
        if (! in_array($action, ['inventory', 'copy', 'verify'], true) ||
            ! in_array($from, ['onboarding-local', 'onboarding-r2'], true) ||
            ! in_array($to, ['onboarding-local', 'onboarding-r2'], true) || $from === $to) {
            $this->error('Use inventory/copy/verify and distinct onboarding-local/onboarding-r2 disks.');

            return self::FAILURE;
        }
        if ($action !== 'inventory' && ! $this->option('writers-paused')) {
            $this->error('Stop all writers and supply --writers-paused before copying/verifying.');

            return self::FAILURE;
        }
        try {
            $rows = DB::table('onboarding_documents');
            $result = $action === 'inventory'
                ? ['documents' => $rows->count(), 'bytes' => (int) $rows->sum('size')]
                : $transfer->run($rows->orderBy('id')->cursor(), $from, $to, $action === 'copy');
            $this->info(json_encode($result, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            // SDK exceptions can contain signed URLs or request details. Never print them.
            $this->error('Storage verification failed. Preserve sources, investigate privately and do not switch drivers.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
