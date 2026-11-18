<?php

namespace App\Console\Commands;

use App\Services\PresentationRegistrations;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class StagingSeedPresentationRegistrations extends Command
{
    protected $signature = 'staging:seed-presentation-registrations {--dry-run : Check every listed source and target and print the plan without writes}';

    protected $description = 'Register the 13 listed Northline presentation leads with the presentation Brand once, without email';

    public function handle(PresentationRegistrations $registrations): int
    {
        try {
            if (! config('staging.enabled') || DB::getDriverName() !== 'pgsql'
                || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator') {
                throw new \RuntimeException('Requires staging mode, PostgreSQL and the migration database role.');
            }
            $this->line(json_encode($registrations->provision((bool) $this->option('dry-run')), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            // Database exceptions can contain bindings. Never print raw SQL.
            $this->error($error instanceof \RuntimeException && ! $error instanceof QueryException
                ? $error->getMessage() : 'Registration provisioning failed; transaction rolled back.');

            return self::FAILURE;
        }
    }
}
