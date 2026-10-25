<?php

namespace App\Http\Controllers;

use App\Services\InvoiceDrafts;
use App\Services\ManualPayments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ManualPaymentController extends Controller
{
    public function index(Request $request, InvoiceDrafts $drafts, ManualPayments $payments): mixed
    {
        $org = $request->session()->get('active_org_id');
        $drafts->authorize((int) $request->user()->id, $org);
        $filters = $request->validate(['cursor' => ['nullable', 'uuid'], 'draft_cursor' => ['nullable', 'uuid']]);
        $cursor = $filters['cursor'] ?? null;
        $listing = $payments->listing($cursor);
        $payload = ['workspace' => ['id' => $org, 'name' => DB::table('organizations')->where('id', $org)->value('name')], 'payments' => $listing['items'], 'nextCursor' => $listing['nextCursor'], 'drafts' => $drafts->listing($filters['draft_cursor'] ?? null)];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('Payments', $payload);
    }

    public function mutate(Request $request, InvoiceDrafts $drafts, ManualPayments $payments, ?string $payment = null, string $action = 'record'): mixed
    {
        $org = $request->session()->get('active_org_id');
        $drafts->authorize((int) $request->user()->id, $org);
        $key = validator(['key' => $request->header('Idempotency-Key') ?? $request->input('command_id')], ['key' => ['required', 'uuid']])->validate()['key'];
        $money = ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/D'];
        $version = ['required', 'integer', 'min:1', 'max:2147483646'];
        $rules = match ($action) {
            'record' => ['currency' => ['required', 'regex:/^[A-Z]{3}$/D'], 'amount_minor' => $money,
                'received_on' => ['required', 'date_format:Y-m-d'], 'evidence_reference' => ['required', 'string', 'max:160'], 'correction_of' => ['nullable', 'uuid']],
            'allocate' => ['version' => $version, 'invoice_id' => ['required', 'uuid'], 'invoice_version' => $version, 'amount_minor' => $money],
            'reverse' => ['reason' => ['required', 'string', 'max:240']],
            'void' => ['version' => $version],
        };
        $data = $request->validate($rules);
        if ($action === 'record') {
            $data['correction_of'] = $data['correction_of'] ?? null;
        }
        foreach (['version', 'invoice_version'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        $id = $payments->command((int) $request->user()->id, $org, $key, $action, $data, $payment);

        $message = match ($action) {
            'record' => 'Payment recorded',
            'allocate' => 'Payment allocated',
            'reverse' => 'Allocation reversed',
            'void' => 'Payment voided',
            default => 'Payment saved',
        };

        return $this->completed($request, ['id' => $id, 'message' => $message], '/finance/payments', $action === 'record' ? 201 : 200);
    }
}
