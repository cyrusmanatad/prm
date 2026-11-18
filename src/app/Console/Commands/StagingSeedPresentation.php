<?php

namespace App\Console\Commands;

use App\Services\PresentationDataset;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class StagingSeedPresentation extends Command
{
    protected $signature = 'staging:seed-presentation {roster : Absolute private JSON path outside the application and Git checkout} {--dry-run : Inspect ownership and print the manifest without writes}';

    protected $description = 'Provision the versioned Northline Living presentation dataset once, without email';

    public function handle(PresentationDataset $dataset): int
    {
        try {
            if (! config('staging.enabled') || DB::getDriverName() !== 'pgsql'
                || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator') {
                throw new \RuntimeException('Requires staging mode, PostgreSQL and the migration database role.');
            }
            $result = $dataset->provision($this->argument('roster'), (bool) $this->option('dry-run'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            // Database exceptions can contain bindings. Never print credentials or raw SQL.
            $this->error($error instanceof \RuntimeException && ! $error instanceof QueryException
                ? $error->getMessage() : 'Presentation provisioning failed; transaction rolled back. Inspect private diagnostics.');

            return self::FAILURE;
        }
    }
}
