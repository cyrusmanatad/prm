<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StagingStorageProbe extends Command
{
    protected $signature = 'staging:storage-probe';

    protected $description = 'Write, read and delete one unique synthetic object on the R2 disk';

    public function handle(): int
    {
        if (! config('staging.enabled')) {
            $this->error('Requires staging mode.');

            return self::FAILURE;
        }
        $key = 'storage-probes/'.Str::uuid();
        try {
            $disk = Storage::disk('onboarding-r2');
            try {
                $bytes = 'Synthetic WeavDash staging probe '.Str::uuid();
                if (! $disk->put($key, $bytes) || $disk->get($key) !== $bytes || $disk->size($key) !== strlen($bytes)) {
                    throw new RuntimeException('Write/read mismatch.');
                }
            } finally {
                if (! $disk->delete($key) || $disk->exists($key)) {
                    throw new RuntimeException('Probe cleanup failed.');
                }
            }
        } catch (Throwable) {
            $this->error('R2 probe failed; investigate privately. Check storage-probes/ for a leftover synthetic object.');

            return self::FAILURE;
        }
        $this->info('R2 synthetic write/read/size/delete passed.');

        return self::SUCCESS;
    }
}
