<?php

namespace App\Http\Controllers;

use App\Services\FollowUpDrafts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FollowUpController extends Controller
{
    public function save(Request $request, FollowUpDrafts $drafts, ?string $draft = null): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'pipeline_entry_id' => [$draft ? 'prohibited' : 'required', 'uuid'],
            'version' => [$draft ? 'required' : 'nullable', 'integer', 'min:1', 'max:2147483646'],
            'recipient' => ['required', 'email:rfc', 'max:255', 'not_regex:/[\r\n]/'],
            'subject' => ['required', 'string', 'max:240', 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:10000'],
        ]);
        $id = $drafts->save((int) $request->user()->id, $request->session()->get('active_org_id'), $data, $draft);

        return $this->completed($request, ['id' => $id, 'message' => 'Draft saved for review'], '/automation', $draft ? 200 : 201);
    }

    public function handoff(Request $request, FollowUpDrafts $drafts, string $draft): Response
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646']]);
        $text = $drafts->transition((int) $request->user()->id, $request->session()->get('active_org_id'), $draft, $data['version'], 'handoff');

        return response($text)->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="follow-up.txt"')->header('Cache-Control', 'no-store, private');
    }

    public function transition(Request $request, FollowUpDrafts $drafts, string $draft, string $action): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646']]);
        $drafts->transition((int) $request->user()->id, $request->session()->get('active_org_id'), $draft, $data['version'], $action);

        return $this->completed($request, ['message' => $action === 'approve' ? 'Approved for manual sending; no email sent' : 'Draft discarded'], '/automation');
    }
}
