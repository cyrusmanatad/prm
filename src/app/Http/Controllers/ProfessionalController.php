<?php

namespace App\Http\Controllers;

use App\Services\InvoiceDrafts;
use App\Services\PilotEntitlements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProfessionalController extends Controller
{
    public function index(Request $request, PilotEntitlements $entitlements): mixed
    {
        $workspace = DB::table('organizations')->where('id', $request->session()->get('active_org_id'))->first(['id', 'name', 'kind', 'experience']);
        abort_unless($workspace?->experience === 'professional', 404);
        $payload = ['workspace' => $workspace, 'subscription' => $entitlements->state($workspace->id)];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('Professional', $payload);
    }

    public function export(Request $request, string $resource, InvoiceDrafts $finance): mixed
    {
        $org = $request->session()->get('active_org_id');
        abort_unless(DB::table('organizations')->where('id', $org)->value('experience') === 'professional', 404);
        $columns = match ($resource) {
            'leads' => ['pipeline_entries', ['id', 'brand_name', 'contact_name', 'contact_email', 'stage', 'currency', 'value_minor', 'next_action_due', 'notes']],
            'contacts' => ['brand_contacts', ['id', 'name', 'email', 'company', 'notes']],
            'reminders' => ['reminders', ['id', 'title', 'due_at', 'completed_at']],
            'invoices' => ['invoice_drafts', ['id', 'recipient_name', 'currency', 'status', 'total_minor', 'due_date', 'lines']],
            'payments' => ['manual_payments', ['id', 'currency', 'amount_minor', 'received_on', 'evidence_reference', 'status', 'correction_of', 'allocations', 'reversals']],
            default => abort(404),
        };
        if (in_array($resource, ['invoices', 'payments'], true)) {
            $finance->authorize((int) $request->user()->id, $org);
        }
        // Materialize within the tenant transaction, never stream deferred RLS queries.
        $file = fopen('php://temp', 'w+');
        fputcsv($file, array_map(fn ($column) => $column === 'brand_name' ? 'client_name' : $column, $columns[1]), escape: '');
        foreach (DB::table($columns[0])->where('org_id', $org)->orderBy('id')->cursor() as $row) {
            if ($resource === 'invoices') {
                $row->lines = json_encode(DB::table('invoice_draft_lines')->where('org_id', $org)->where('invoice_id', $row->id)->orderBy('position')
                    ->get(['position', 'description', 'quantity', DB::raw('unit_price_minor::text AS unit_price_minor'), DB::raw('total_minor::text AS total_minor')])->all(), JSON_THROW_ON_ERROR);
            }
            if ($resource === 'payments') {
                $allocations = DB::table('payment_allocations')->where('org_id', $org)->where('payment_id', $row->id)->orderBy('id')
                    ->get(['id', 'invoice_id', DB::raw('amount_minor::text AS amount_minor'), 'created_at']);
                $row->allocations = json_encode($allocations->all(), JSON_THROW_ON_ERROR);
                $row->reversals = json_encode(DB::table('payment_allocation_reversals')->where('org_id', $org)->whereIn('allocation_id', $allocations->pluck('id'))->orderBy('id')
                    ->get(['id', 'allocation_id', 'reason', 'created_at'])->all(), JSON_THROW_ON_ERROR);
            }
            $values = array_map(function ($column) use ($row) {
                $value = (string) ($row->{$column} ?? '');

                return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
            }, $columns[1]);
            fputcsv($file, $values, escape: '');
        }
        rewind($file);
        $csv = stream_get_contents($file);
        fclose($file);

        return response($csv)->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="professional-'.$resource.'.csv"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
