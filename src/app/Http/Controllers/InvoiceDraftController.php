<?php

namespace App\Http\Controllers;

use App\Services\InvoiceDrafts;
use App\Services\PilotEntitlements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InvoiceDraftController extends Controller
{
    public function index(Request $request, InvoiceDrafts $drafts, ?string $invoice = null): mixed
    {
        $org = $request->session()->get('active_org_id');
        $drafts->authorize((int) $request->user()->id, $org);
        $cursor = $request->validate(['cursor' => ['nullable', 'uuid']])['cursor'] ?? null;
        $payload = ['workspace' => ['id' => $org, 'name' => DB::table('organizations')->where('id', $org)->value('name')],
            ...($invoice ? ['invoice' => $drafts->get($invoice)] : ['invoices' => $drafts->listing($cursor)])];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('Invoices', $payload);
    }

    public function entitlements(Request $request, InvoiceDrafts $drafts, PilotEntitlements $entitlements): mixed
    {
        $org = $request->session()->get('active_org_id');
        $drafts->authorize((int) $request->user()->id, $org);
        $payload = ['entitlements' => $entitlements->state($org)];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('Entitlements', $payload);
    }

    public function mutate(Request $request, InvoiceDrafts $drafts, ?string $invoice = null): mixed
    {
        $org = $request->session()->get('active_org_id');
        $drafts->authorize((int) $request->user()->id, $org);
        $action = $request->is('*/void') ? 'void' : ($invoice ? 'update' : 'create');
        $key = validator(['key' => $request->header('Idempotency-Key') ?? $request->input('command_id')], ['key' => ['required', 'uuid']])->validate()['key'];
        $rules = $invoice ? ['version' => ['required', 'integer', 'min:1', 'max:2147483646']] : [];
        if ($action !== 'void') {
            $rules += ['recipient_name' => ['required', 'string', 'max:160'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/D'],
                'due_date' => ['nullable', 'date_format:Y-m-d'], 'lines' => ['required', 'array', 'min:1', 'max:50'],
                'lines.*.description' => ['required', 'string', 'max:240'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
                'lines.*.unit_price_minor' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/D']];
        }
        $data = $request->validate($rules);
        if (isset($data['version'])) {
            $data['version'] = (int) $data['version'];
        }
        if ($action !== 'void') {
            $data['due_date'] = $data['due_date'] ?? null;
            $data['lines'] = array_map(fn ($l) => ['description' => $l['description'], 'quantity' => (int) $l['quantity'], 'unit_price_minor' => $l['unit_price_minor']], $data['lines']);
        }
        $id = $drafts->command((int) $request->user()->id, $org, $key, $action, $data, $invoice);

        return $this->completed($request, ['id' => $id, 'message' => 'Draft '.$action.' completed.'], '/finance/invoices/'.$id, $action === 'create' ? 201 : 200);
    }
}
