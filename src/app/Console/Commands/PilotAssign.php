<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PilotAssign extends Command
{
    protected $signature = 'pilot:assign {workspace} {plan} {starts} {ends} {--operator=} {--reason=}';

    protected $description = 'Append a time-limited local pilot assignment; preserve existing data';

    public static function guard(Command $command): void
    {
        if (! app()->environment(['local', 'testing']) || DB::getDriverName() !== 'pgsql'
            || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator') {
            throw new \RuntimeException('Pilot administration requires local/testing migration credentials.');
        }
        validator(['operator' => $command->option('operator'), 'reason' => $command->option('reason')], ['operator' => 'required|string|max:160', 'reason' => 'required|string|max:240'])->validate();
    }

    public static function audit(Command $command, string $event, string $id, ?string $org): void
    {
        DB::table('pilot_admin_events')->insert(['id' => (string) Str::uuid(), 'operator' => $command->option('operator'), 'reason' => $command->option('reason'), 'event' => $event, 'subject_id' => $id, 'org_id' => $org]);
    }

    public function handle(): int
    {
        self::guard($this);
        $data = validator(['org_id' => $this->argument('workspace'), 'plan_id' => $this->argument('plan'), 'starts_at' => $this->argument('starts'), 'ends_at' => $this->argument('ends')],
            ['org_id' => 'required|uuid', 'plan_id' => 'required|uuid', 'starts_at' => 'required|date_format:Y-m-d\TH:i:sP', 'ends_at' => 'required|date_format:Y-m-d\TH:i:sP|after:starts_at'])->validate();
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $data): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['pilot:'.$data['org_id']]);
            $version = (int) DB::table('pilot_assignments')->where('org_id', $data['org_id'])->max('version') + 1;
            DB::table('pilot_assignments')->insert([...$data, 'id' => $id, 'version' => $version]);
            self::audit($this, 'pilot.assigned', $id, $data['org_id']);
        });
        $this->line($id);

        return self::SUCCESS;
    }
}
