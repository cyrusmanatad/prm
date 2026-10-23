<?php

namespace App\Http\Queries;

use App\Enums\PipelineStage;
use App\Services\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Shared projection for the partner page and legacy JSON clients; requires WorkspaceContext. */
class PipelinePage
{
    public function data(Request $request, Pipeline $pipeline): array
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

        return $payload;
    }
}
