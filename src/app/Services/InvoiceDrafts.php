<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** All methods run inside the locked TenantContext transaction. */
class InvoiceDrafts
{
    public function authorize(int $actor, string $org): void
    {
        abort_unless(DB::selectOne("SELECT app_has_membership(?::uuid, ARRAY['owner']) AS allowed", [$org])->allowed, 403);
    }

    public function listing(?string $cursor): array
    {
        $query = DB::table('invoice_drafts')->orderByDesc('created_at')->orderByDesc('id');
        if ($cursor) {
            $row = DB::table('invoice_drafts')->where('id', $cursor)->first(['created_at', 'id']);
            abort_unless($row, 404);
            $query->whereRaw('(created_at,id)<(?::timestamptz,?::uuid)', [$row->created_at, $row->id]);
        }
        $rows = $query->limit(26)->get()->map(fn ($r) => $this->project($r))->all();
        $more = count($rows) > 25;
        $rows = array_slice($rows, 0, 25);

        return ['items' => $rows, 'nextCursor' => $more ? end($rows)['id'] : null];
    }

    public function get(string $id): array
    {
        $row = DB::table('invoice_drafts')->where('id', $id)->first();
        abort_unless($row, 404);
        $data = $this->project($row);
        $data['lines'] = DB::table('invoice_draft_lines')->where('invoice_id', $id)->orderBy('position')->get()
            ->map(fn ($l) => ['description' => $l->description, 'quantity' => $l->quantity,
                'unit_price_minor' => (string) $l->unit_price_minor, 'total_minor' => (string) $l->total_minor])->all();

        return $data;
    }

    private function project(object $row): array
    {
        return ['id' => $row->id, 'recipient_name' => $row->recipient_name, 'currency' => $row->currency,
            'due_date' => $row->due_date, 'status' => $row->status, 'total_minor' => (string) $row->total_minor, 'version' => $row->version];
    }

    public function command(int $actor, string $org, string $key, string $action, array $data, ?string $id): string
    {
        $this->authorize($actor, $org);
        ManualPayments::lock($org);
        $operation = 'invoice.'.$action.($id ? '.'.$id : '');
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["$org:$actor:$operation:$key"]);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $prior = DB::table('financial_commands')->where(['org_id' => $org, 'actor_id' => $actor, 'operation' => $operation, 'command_id' => $key])->first();
        if ($prior) {
            $this->conflict($prior->payload_hash !== $hash, 'This command key was already used with different details.');

            return $prior->result_id;
        }
        if ($id) {
            $row = DB::table('invoice_drafts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $this->conflict($row->version !== $data['version'] || $row->status !== 'draft', 'This draft changed or was voided. Reload before continuing.');
        }
        if ($id && app(ManualPayments::class)->active()->where('invoice_id', $id)->exists()) {
            $this->conflict(true, 'Reverse payment allocations before changing or voiding this draft.');
        }
        if ($action === 'void') {
            DB::table('invoice_drafts')->where('id', $id)->update(['status' => 'voided', 'version' => $row->version + 1, 'updated_at' => now()]);
        } else {
            try {
                $money = InvoiceMoney::calculate($data['lines']);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['lines' => $e->getMessage()]);
            }
            $fields = ['recipient_name' => $data['recipient_name'], 'currency' => $data['currency'], 'due_date' => $data['due_date'], 'total_minor' => $money['total_minor']];
            if (! $id) {
                $id = (string) Str::uuid();
                DB::table('invoice_drafts')->insert([...$fields, 'id' => $id, 'org_id' => $org]);
            } else {
                DB::table('invoice_drafts')->where('id', $id)->update([...$fields, 'version' => $row->version + 1, 'updated_at' => now()]);
                DB::table('invoice_draft_lines')->where('invoice_id', $id)->delete();
            }
            foreach ($money['lines'] as $i => $line) {
                DB::table('invoice_draft_lines')->insert(['org_id' => $org, 'invoice_id' => $id, 'position' => $i + 1,
                    'description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price_minor' => $line['unit_price_minor']]);
            }
        }
        DB::table('financial_commands')->insert(['org_id' => $org, 'actor_id' => $actor, 'operation' => $operation, 'command_id' => $key, 'payload_hash' => $hash, 'result_id' => $id]);
        app(Audit::class)->record($actor, $org, 'invoice_draft.'.$action, $id);

        return $id;
    }

    private function conflict(bool $condition, string $message): void
    {
        if ($condition) {
            throw ValidationException::withMessages(['version' => $message])->status(409);
        }
    }
}
