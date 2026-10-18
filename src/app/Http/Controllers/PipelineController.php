<?php

namespace App\Http\Controllers;

use App\Enums\PipelineStage;
use App\Services\Pipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PipelineController extends Controller
{
    public function index(Request $request, Pipeline $pipeline): JsonResponse|Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:160'], 'stage' => ['nullable', Rule::enum(PipelineStage::class)],
            'due' => ['nullable', 'boolean'], 'cursor' => ['nullable', 'uuid'], 'entry' => ['nullable', 'uuid'],
        ]);
        $orgId = $request->session()->get('active_org_id');
        $payload = [...$pipeline->listing($orgId, $filters),
            'workspace' => ['id' => $orgId, 'name' => DB::table('organizations')->where('id', $orgId)->value('name')],
            'canWrite' => $pipeline->canWrite((int) $request->user()->id, $orgId),
            'stages' => PipelineStage::options(),
            'filters' => ['q' => $filters['q'] ?? '', 'stage' => $filters['stage'] ?? '', 'due' => (bool) ($filters['due'] ?? false)],
            'selected' => empty($filters['entry']) ? null : $pipeline->detail($orgId, $filters['entry']),
        ];
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($payload);
        }

        return Inertia::render('Pipeline', $payload);
    }

    public function store(Request $request, Pipeline $pipeline): JsonResponse|RedirectResponse
    {
        $data = $this->details($request);
        $id = $pipeline->create((int) $request->user()->id, $request->session()->get('active_org_id'), $data);

        return $this->completed($request, ['id' => $id, 'message' => 'Lead captured.'], '/pipeline?entry='.$id, 201);
    }

    public function update(Request $request, Pipeline $pipeline, string $entry): JsonResponse|RedirectResponse
    {
        $data = $this->details($request);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646']])['version'];
        $pipeline->change((int) $request->user()->id, $request->session()->get('active_org_id'), $entry, (int) $version, 'updated', $data);

        return $this->completed($request, ['message' => 'Lead saved.'], '/pipeline?entry='.$entry);
    }

    public function stage(Request $request, Pipeline $pipeline, string $entry): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1', 'max:2147483646'], 'stage' => ['required', Rule::enum(PipelineStage::class)],
        ]);
        $pipeline->change((int) $request->user()->id, $request->session()->get('active_org_id'), $entry, (int) $data['version'], 'stage', ['stage' => $data['stage']]);

        return $this->completed($request, ['message' => 'Stage updated.'], '/pipeline?entry='.$entry);
    }

    public function snooze(Request $request, Pipeline $pipeline, string $entry): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1', 'max:2147483646'],
            'next_action_due' => ['required', 'date', 'after:now', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/'],
        ]);
        $pipeline->change((int) $request->user()->id, $request->session()->get('active_org_id'), $entry, (int) $data['version'], 'snoozed', [
            'next_action_due' => Carbon::parse($data['next_action_due'])->utc()->toIso8601String(),
        ]);

        return $this->completed($request, ['message' => 'Next action snoozed.'], '/pipeline?entry='.$entry);
    }

    public function activity(Request $request, Pipeline $pipeline, string $entry): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1', 'max:2147483646'],
            'kind' => ['required', Rule::in(['note', 'email', 'chat', 'call'])], 'body' => ['required', 'string', 'max:5000'],
        ]);
        $pipeline->addActivity((int) $request->user()->id, $request->session()->get('active_org_id'), $entry, (int) $data['version'], $data['kind'], $data['body']);

        return $this->completed($request, ['message' => 'Activity recorded.'], '/pipeline?entry='.$entry, 201);
    }

    public function destroy(Request $request, Pipeline $pipeline, string $entry): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646']]);
        $pipeline->delete((int) $request->user()->id, $request->session()->get('active_org_id'), $entry, (int) $data['version']);

        return $this->completed($request, ['message' => 'Lead deleted.'], '/pipeline');
    }

    private function details(Request $request): array
    {
        $data = $request->validate([
            'brand_name' => ['required', 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:120'], 'contact_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'value_minor' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,17})$/'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'next_action_due' => ['nullable', 'date', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/'],
        ]);

        return ['brand_name' => $data['brand_name'], 'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null, 'notes' => $data['notes'] ?? '',
            'value_minor' => $data['value_minor'], 'currency' => $data['currency'],
            'next_action_due' => empty($data['next_action_due']) ? null : Carbon::parse($data['next_action_due'])->utc()->toIso8601String()];
    }
}
