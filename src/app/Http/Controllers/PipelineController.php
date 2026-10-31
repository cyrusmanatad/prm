<?php

namespace App\Http\Controllers;

use App\Enums\PipelineStage;
use App\Http\Queries\PipelinePage;
use App\Services\Pipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class PipelineController extends Controller
{
    public function index(Request $request, Pipeline $pipeline): JsonResponse|RedirectResponse
    {
        if (! $request->expectsJson() || $request->header('X-Inertia')) {
            return redirect('/partner/leads'.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }

        return response()->json(app(PipelinePage::class)->data($request, $pipeline));
    }

    public function store(Request $request, Pipeline $pipeline): JsonResponse|RedirectResponse
    {
        $context = $this->listingContext($request);
        $data = $this->details($request);
        $id = $pipeline->create((int) $request->user()->id, $request->session()->get('active_org_id'), $data);

        return $this->completed($request, ['id' => $id, 'message' => 'Lead captured'], $this->destination($context, $id), 201);
    }

    public function update(Request $request, Pipeline $pipeline, string $entry): JsonResponse|RedirectResponse
    {
        $context = $this->listingContext($request);
        $data = $this->details($request);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646']])['version'];
        $pipeline->change((int) $request->user()->id, $request->session()->get('active_org_id'), $entry, (int) $version, 'updated', $data);

        return $this->completed($request, ['message' => 'Lead saved'], $this->destination($context, $entry));
    }

    public function stage(Request $request, Pipeline $pipeline, string $entry): JsonResponse|RedirectResponse
    {
        $context = $this->listingContext($request);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1', 'max:2147483646'], 'stage' => ['required', Rule::enum(PipelineStage::class)],
        ]);
        $pipeline->change((int) $request->user()->id, $request->session()->get('active_org_id'), $entry, (int) $data['version'], 'stage', ['stage' => $data['stage']]);
        $stage = $data['stage'] instanceof PipelineStage ? $data['stage']->value : (string) $data['stage'];
        $match = collect(PipelineStage::options())->firstWhere('value', $stage);
        $label = is_array($match) ? $match['label'] : ucfirst($stage);

        return $this->completed($request, ['message' => 'Stage updated to '.$label], $this->destination($context, $entry));
    }

    public function snooze(Request $request, Pipeline $pipeline, string $entry): JsonResponse|RedirectResponse
    {
        $context = $this->listingContext($request);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1', 'max:2147483646'],
            'next_action_due' => ['required', 'date', 'after:now', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/'],
        ]);
        $pipeline->change((int) $request->user()->id, $request->session()->get('active_org_id'), $entry, (int) $data['version'], 'snoozed', [
            'next_action_due' => Carbon::parse($data['next_action_due'])->utc()->toIso8601String(),
        ]);

        return $this->completed($request, ['message' => 'Next action snoozed until '.Carbon::parse($data['next_action_due'])->format('M j')], $this->destination($context, $entry));
    }

    public function activity(Request $request, Pipeline $pipeline, string $entry): JsonResponse|RedirectResponse
    {
        $context = $this->listingContext($request);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1', 'max:2147483646'],
            'kind' => ['required', Rule::in(['note', 'email', 'chat', 'call', 'reply'])], 'body' => ['required', 'string', 'max:5000'],
        ]);
        $pipeline->addActivity((int) $request->user()->id, $request->session()->get('active_org_id'), $entry, (int) $data['version'], $data['kind'], $data['body']);

        return $this->completed($request, ['message' => $data['kind'] === 'reply' ? 'Reply recorded; existing follow-ups cancelled and next action cleared' : 'Activity recorded'], $this->destination($context, $entry), 201);
    }

    public function destroy(Request $request, Pipeline $pipeline, string $entry): JsonResponse|RedirectResponse
    {
        $context = $this->listingContext($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646']]);
        $pipeline->delete((int) $request->user()->id, $request->session()->get('active_org_id'), $entry, (int) $data['version']);

        return $this->completed($request, ['message' => 'Lead deleted'], $this->destination($context));
    }

    /** Only filter values are accepted; callers cannot choose a redirect URL or tenant. */
    private function listingContext(Request $request): array
    {
        $data = $request->validate([
            'pipeline_filters' => ['sometimes', 'array:q,stage,due'],
            'pipeline_filters.q' => ['nullable', 'string', 'max:160'],
            'pipeline_filters.stage' => ['nullable', Rule::enum(PipelineStage::class)],
            'pipeline_filters.due' => ['nullable', 'boolean'],
        ]);

        $filters = $data['pipeline_filters'] ?? [];
        if (array_key_exists('due', $filters)) {
            $filters['due'] = (bool) $filters['due'];
        }

        return array_filter($filters, fn ($value) => $value !== null && $value !== '' && $value !== false);
    }

    private function destination(array $filters, ?string $entry = null): string
    {
        // Mutations restart the matching list: a moved/deleted cursor may no longer be valid.
        $query = http_build_query([...$filters, ...($entry ? ['entry' => $entry] : [])]);

        return '/partner/leads'.($query !== '' ? '?'.$query : '');
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
