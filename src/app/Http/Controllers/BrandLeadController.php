<?php

namespace App\Http\Controllers;

use App\Enums\PipelineStage;
use App\Services\LeadRegistrations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Read-only Brand screens for explicitly registered leads and connected Partners (D38). */
class BrandLeadController extends Controller
{
    public function __construct(private LeadRegistrations $leads) {}

    public function index(Request $request): JsonResponse|RedirectResponse|Response
    {
        if (! $workspace = $this->brand($request)) {
            return redirect('/workspaces');
        }
        $filters = $this->filters($request);
        $listing = $this->leads->listing($workspace->id, $filters, (int) ($request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000']])['page'] ?? 1), $this->leads->clock());

        return $this->respond($request, 'BrandLeadRegistrations', [
            'workspace' => $workspace, 'registrations' => $listing['rows'],
            'pagination' => ['page' => $listing['page'], 'perPage' => $listing['perPage'], 'total' => $listing['total']],
            'filters' => $filters, 'partners' => $this->leads->partnerOptions($workspace->id), 'stages' => PipelineStage::options(),
        ]);
    }

    public function show(Request $request, string $registration): JsonResponse|RedirectResponse|Response
    {
        if (! $workspace = $this->brand($request)) {
            return redirect('/workspaces');
        }
        $from = $request->validate(['from' => ['nullable', Rule::in(['partner'])], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $filters = $this->filters($request);
        // Hidden, withdrawn, ended, paused and foreign registrations all answer the same way.
        abort_unless($detail = $this->leads->detail($workspace->id, $registration), 404);

        return $this->respond($request, 'BrandLeadRegistration', [
            'workspace' => $workspace, 'registration' => $detail, 'stages' => PipelineStage::options(),
            'back' => ['from' => $from['from'] ?? 'list', 'page' => (int) ($from['page'] ?? 1), 'filters' => $filters],
        ]);
    }

    public function partner(Request $request, string $relationship): JsonResponse|RedirectResponse|Response
    {
        if (! $workspace = $this->brand($request)) {
            return redirect('/workspaces');
        }
        $page = (int) ($request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000']])['page'] ?? 1);
        $now = $this->leads->clock();
        abort_unless($partner = $this->leads->partner($workspace->id, $relationship, $now), 404);
        $listing = $this->leads->listing($workspace->id, ['partner' => $relationship], $page, $now, LeadRegistrations::ROSTER_PER_PAGE);

        return $this->respond($request, 'BrandPartner', [
            'workspace' => $workspace, 'partner' => $partner, 'registrations' => $listing['rows'], 'stages' => PipelineStage::options(),
            'pagination' => ['page' => $listing['page'], 'perPage' => $listing['perPage'], 'total' => $listing['total']],
            'canManage' => $this->leads->canManage($workspace->id),
        ]);
    }

    private function brand(Request $request): ?object
    {
        $workspace = DB::table('organizations')->where('id', $request->session()->get('active_org_id'))->first(['id', 'name', 'kind']);

        return $workspace?->kind === 'brand' ? $workspace : null;
    }

    /** Only these keys shape a registration query; none can name another workspace. */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'partner' => ['nullable', 'uuid'], 'stage' => ['nullable', Rule::enum(PipelineStage::class)],
            'q' => ['nullable', 'string', 'max:160'], 'window' => ['nullable', Rule::in(['30d'])],
        ]);

        return ['partner' => $data['partner'] ?? '', 'stage' => $data['stage'] ?? '', 'q' => $data['q'] ?? '', 'window' => $data['window'] ?? ''];
    }

    private function respond(Request $request, string $component, array $data): JsonResponse|Response
    {
        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($data) : Inertia::render($component, $data);
    }
}
