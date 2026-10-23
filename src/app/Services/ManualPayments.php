<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManualPayments
{
    public static function lock(string $org): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['finance:'.$org]);
    }

    public function active(): Builder
    {
        return DB::table('payment_allocations as a')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_allocation_reversals as r')->whereColumn('r.allocation_id', 'a.id'));
    }

    public function listing(?string $cursor = null): array
    {
        $query = DB::table('manual_payments')->orderByDesc('created_at')->orderByDesc('id');
        if ($cursor) {
            $row = DB::table('manual_payments')->where('id', $cursor)->first(['created_at', 'id']);
            abort_unless($row, 404);
            $query->whereRaw('(created_at,id)<(?::timestamptz,?::uuid)', [$row->created_at, $row->id]);
        }
        $rows = $query->limit(26)->get();
        $items = $rows->take(25)->map(function ($p) {
            $allocated = (string) $this->active()->where('payment_id', $p->id)->sum('amount_minor');

            return ['id' => $p->id, 'currency' => $p->currency, 'amount_minor' => (string) $p->amount_minor,
                'received_on' => $p->received_on, 'evidence_reference' => $p->evidence_reference, 'status' => $p->status,
                'version' => $p->version, 'correction_of' => $p->correction_of, 'allocated_minor' => $allocated,
                'allocations' => DB::table('payment_allocations as a')->leftJoin('payment_allocation_reversals as r', 'r.allocation_id', '=', 'a.id')
                    ->where('a.payment_id', $p->id)->orderBy('a.id')->get(['a.id', 'a.invoice_id', 'a.amount_minor', 'r.reason'])->map(fn ($a) => [
                        'id' => $a->id, 'invoice_id' => $a->invoice_id, 'amount_minor' => (string) $a->amount_minor, 'reversal_reason' => $a->reason])->all()];
        })->all();

        return ['items' => $items, 'nextCursor' => $rows->count() > 25 ? end($items)['id'] : null];
    }

    public function command(int $actor, string $org, string $key, string $action, array $data, ?string $target): string
    {
        app(InvoiceDrafts::class)->authorize($actor, $org);
        self::lock($org);
        $op = $action.($target ? '.'.$target : '');
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $scope = ['org_id' => $org, 'actor_id' => $actor, 'operation' => $op, 'command_id' => $key];
        $prior = DB::table('payment_commands')->where($scope)->first();
        if ($prior) {
            $this->ensure($hash === $prior->payload_hash, 'Command key already used with different details.');

            return $prior->result_id;
        }
        $id = (string) Str::uuid();
        if ($action === 'record') {
            $amount = $this->amount($data['amount_minor']);
            $evidence = hash('sha256', strtolower(trim($data['evidence_reference'])));
            $this->ensure(! DB::table('manual_payments')->where('evidence_hash', $evidence)->exists(), 'Evidence reference already recorded, including voided history.');
            if ($data['correction_of']) {
                $old = DB::table('manual_payments')->where('id', $data['correction_of'])->first();
                abort_unless($old, 404);
                $this->ensure($old->status === 'voided', 'Void the original record before recording a correction.');
            }
            DB::table('manual_payments')->insert([...$data, 'id' => $id, 'org_id' => $org, 'amount_minor' => $amount, 'evidence_hash' => $evidence]);
        } elseif ($action === 'reverse') {
            $allocation = DB::table('payment_allocations')->where('id', $target)->first();
            abort_unless($allocation, 404);
            $this->ensure(! DB::table('payment_allocation_reversals')->where('allocation_id', $target)->exists(), 'Allocation already reversed.');
            DB::table('payment_allocation_reversals')->insert(['id' => $id, 'org_id' => $org, 'allocation_id' => $target, 'reason' => $data['reason']]);
        } else {
            $payment = DB::table('manual_payments')->where('id', $target)->lockForUpdate()->first();
            abort_unless($payment, 404);
            $this->ensure($payment->status === 'recorded' && $payment->version === $data['version'], 'Payment changed or was voided. Reload before continuing.');
            if ($action === 'void') {
                $this->ensure(! $this->active()->where('payment_id', $target)->exists(), 'Reverse allocations before voiding this payment.');
                DB::table('manual_payments')->where('id', $target)->update(['status' => 'voided', 'version' => $payment->version + 1]);
                $id = $target;
            } else {
                $invoice = DB::table('invoice_drafts')->where('id', $data['invoice_id'])->lockForUpdate()->first();
                abort_unless($invoice, 404);
                $this->ensure($invoice->status === 'draft' && $invoice->version === $data['invoice_version'], 'Draft changed or was voided. Reload before allocating.');
                $this->ensure($invoice->currency === $payment->currency, 'Payment and draft currencies must match.');
                $amount = $this->amount($data['amount_minor']);
                $remainingPayment = $payment->amount_minor - (int) $this->active()->where('payment_id', $target)->sum('amount_minor');
                $remainingInvoice = $invoice->total_minor - (int) $this->active()->where('invoice_id', $invoice->id)->sum('amount_minor');
                $this->ensure($amount <= $remainingPayment && $amount <= $remainingInvoice, 'Allocation exceeds the unallocated payment or draft balance.');
                DB::table('payment_allocations')->insert(['id' => $id, 'org_id' => $org, 'payment_id' => $target, 'invoice_id' => $invoice->id, 'amount_minor' => $amount]);
                DB::table('manual_payments')->where('id', $target)->update(['version' => $payment->version + 1]);
            }
        }
        DB::table('payment_commands')->insert([...$scope, 'payload_hash' => $hash, 'result_id' => $id]);
        app(Audit::class)->record($actor, $org, 'manual_payment.'.$action, $id);

        return $id;
    }

    private function amount(string $amount): int
    {
        try {
            InvoiceMoney::calculate([['quantity' => 1, 'unit_price_minor' => $amount]]);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount_minor' => $e->getMessage()]);
        }
        if ($amount === '0') {
            throw ValidationException::withMessages(['amount_minor' => 'Amount must be positive.']);
        }

        return (int) $amount;
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['version' => $message])->status(409);
        }
    }
}
