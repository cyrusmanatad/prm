<?php

namespace App\Http\Controllers;

use App\Services\OutreachTemplates;
use App\Services\Pipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OutreachTemplateController extends Controller
{
    public function index(Request $request, OutreachTemplates $templates, Pipeline $pipeline): JsonResponse|Response|RedirectResponse
    {
        if ($request->is('outreach-templates') && (! $request->expectsJson() || $request->header('X-Inertia'))) {
            return redirect('/partner/outreach-templates'.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }

        $org = $request->session()->get('active_org_id');
        $payload = ['templates' => $templates->listing($org), 'canWrite' => $pipeline->canWrite((int) $request->user()->id, $org),
            'workspace' => ['id' => $org, 'name' => DB::table('organizations')->where('id', $org)->value('name')]];

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json($payload) : Inertia::render('OutreachTemplates', $payload);
    }

    public function store(Request $request, OutreachTemplates $templates): JsonResponse|RedirectResponse
    {
        $id = $templates->save((int) $request->user()->id, $request->session()->get('active_org_id'), $this->details($request));

        return $this->completed($request, ['id' => $id, 'message' => 'Template created'], '/partner/outreach-templates', 201);
    }

    public function update(Request $request, OutreachTemplates $templates, string $template): JsonResponse|RedirectResponse
    {
        $templates->save((int) $request->user()->id, $request->session()->get('active_org_id'), $this->details($request), $template, $this->version($request));

        return $this->completed($request, ['message' => 'Template saved'], '/partner/outreach-templates');
    }

    public function destroy(Request $request, OutreachTemplates $templates, string $template): JsonResponse|RedirectResponse
    {
        $templates->delete((int) $request->user()->id, $request->session()->get('active_org_id'), $template, $this->version($request));

        return $this->completed($request, ['message' => 'Template deleted'], '/partner/outreach-templates');
    }

    private function details(Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'subject' => ['nullable', 'string', 'max:240'],
            'body' => ['required', 'string', 'max:5000']]);

        return ['name' => $data['name'], 'subject' => $data['subject'] ?? '', 'body' => $data['body']];
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646']])['version'];
    }
}
