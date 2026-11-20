<?php

namespace App\Http\Controllers;

use App\Enums\PipelineStage;
use App\Services\LeadRegistrations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Partner side of Brand lead visibility (D38): explicit registration, withdrawal and the shared profile. */
class LeadRegistrationController extends Controller
{
    public function __construct(private LeadRegistrations $leads) {}

    public function index(Request $request): JsonResponse|RedirectResponse|Response
    {
        $org = $request->session()->get('active_org_id');
        $workspace = DB::table('organizations')->where('id', $org)->first(['id', 'name', 'kind', 'experience']);
        if ($workspace?->kind !== 'creator') {
            return redirect('/workspaces');
        }
        $browser = ! $request->expectsJson() || $request->header('X-Inertia');
        if ($workspace->experience === 'professional' && ! $request->is('professional/*') && $browser) {
            return redirect('/professional/brand-sharing'.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }
        $query = $request->validate(['lead' => ['nullable', 'uuid'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'state' => ['nullable', 'in:'.implode(',', LeadRegistrations::OWN_STATES)], 'brand' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:160']]);
        $lead = empty($query['lead']) ? null : $this->leads->source($org, $query['lead']);
        abort_if(! empty($query['lead']) && ! $lead, 404);
        $filters = ['state' => $query['state'] ?? 'all', 'brand' => $query['brand'] ?? '', 'q' => trim($query['q'] ?? '')];
        $own = $this->leads->own($org, (int) ($query['page'] ?? 1), $filters);
        $brands = $this->leads->brands($org);
        $canManage = $this->leads->canManage($org);
        $everything = $filters['brand'] === '' && $filters['q'] === '' ? $own['counts'] : $this->leads->own($org, 1)['counts'];
        $data = [
            'workspace' => $workspace, 'canManage' => $canManage, 'brands' => $brands, 'lead' => $lead,
            'registrations' => $own['rows'], 'pagination' => ['page' => $own['page'], 'perPage' => $own['perPage'], 'total' => $own['total']],
            'counts' => $own['counts'], 'filters' => $filters,
            'summary' => ['brands' => count($brands), 'shared' => $everything['shared'], 'notShared' => $everything['not_shared']],
            'leads' => $canManage ? $this->leads->shareable($org) : [],
            'profile' => $this->leads->profile($org), 'stages' => PipelineStage::options(),
        ];

        return $browser ? Inertia::render('BrandSharing', $data) : response()->json($data);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['partnership' => ['required', 'uuid'], 'entry' => ['required', 'uuid'], ...$this->shared()]);
        $result = $this->leads->register($data['partnership'], $data['entry'], $data['display_name'], $data['summary'] ?? '', (bool) ($data['share_contact'] ?? false));
        $message = ['created' => 'Lead registered with the Brand', 'existing' => 'This lead is already registered with the Brand', 'reshared' => 'Lead shared with the Brand again'][$result['status']];

        return $this->completed($request, ['id' => $result['id'], 'status' => $result['status'], 'message' => $message], '/partner/brand-sharing', $result['status'] === 'created' ? 201 : 200);
    }

    public function update(Request $request, string $registration): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646'], ...$this->shared()]);
        $this->leads->update($registration, (int) $data['version'], $data['display_name'], $data['summary'] ?? '', (bool) ($data['share_contact'] ?? false));

        return $this->completed($request, ['message' => 'Shared details saved'], '/partner/brand-sharing');
    }

    public function destroy(Request $request, string $registration): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646']]);
        $this->leads->withdraw($registration, (int) $data['version']);

        return $this->completed($request, ['message' => 'Registration withdrawn'], '/partner/brand-sharing');
    }

    public function profile(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'contact_name' => ['nullable', 'string', 'max:120'], 'contact_email' => ['nullable', 'email:rfc', 'max:255'],
            // Only web addresses: scripts and other schemes are refused here and by the table constraint.
            'website_url' => ['nullable', 'string', 'max:255', 'url:http,https', 'regex:/^https?:\/\/\S+$/i'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $this->leads->saveProfile((int) $data['version'], $data);

        return $this->completed($request, ['message' => 'Shared profile saved'], '/partner/brand-sharing');
    }

    private function shared(): array
    {
        return ['display_name' => ['required', 'string', 'max:160'], 'summary' => ['nullable', 'string', 'max:1000'], 'share_contact' => ['sometimes', 'boolean']];
    }
}
