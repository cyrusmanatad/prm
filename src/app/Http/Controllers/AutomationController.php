<?php

namespace App\Http\Controllers;

use App\Services\BusinessSchedules;
use App\Services\Pipeline;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    public function index(Request $request, BusinessSchedules $schedules, Pipeline $pipeline): JsonResponse|Response
    {
        $org = $request->session()->get('active_org_id');
        $actor = (int) $request->user()->id;
        $payload = ['workspace' => ['id' => $org, 'name' => DB::table('organizations')->where('id', $org)->value('name')],
            'preferences' => $schedules->preferences($actor, $org), 'schedules' => DB::table('business_schedules')->orderByDesc('created_at')->limit(100)->get(),
            'reminders' => DB::table('reminders')->whereNull('completed_at')->orderBy('due_at')->orderBy('id')->limit(100)->get(['id', 'title', 'due_at']),
            'notices' => DB::table('notification_outbox')->where('actor_id', $actor)->whereNotNull('schedule_run_id')->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get(['id', 'kind', 'state', 'created_at']),
            'canWrite' => $pipeline->canWrite($actor, $org), 'timezones' => DateTimeZone::listIdentifiers()];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('Automation', $payload);
    }

    public function preferences(Request $request, BusinessSchedules $schedules): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'reminders' => ['required', 'boolean'], 'weekly_digest' => ['required', 'boolean'], 'follow_up_drafts' => ['required', 'boolean']]);
        $schedules->savePreferences((int) $request->user()->id, $request->session()->get('active_org_id'), $data);

        return $this->completed($request, ['message' => 'Automation preferences saved'], '/automation');
    }

    public function save(Request $request, BusinessSchedules $schedules, ?string $schedule = null): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'kind' => ['required', Rule::in(array_keys(BusinessSchedules::PREFERENCES))],
            'consent' => ['required', 'accepted'], 'version' => [$schedule ? 'required' : 'nullable', 'integer', 'min:1', 'max:2147483646'],
            'subject_id' => ['required_unless:kind,weekly_digest', 'nullable', 'uuid', 'prohibited_if:kind,weekly_digest'],
            'weekday' => ['required_if:kind,weekly_digest', 'nullable', 'integer', 'between:1,7', 'prohibited_unless:kind,weekly_digest'],
            'local_time' => ['required_if:kind,weekly_digest', 'nullable', 'date_format:H:i', 'prohibited_unless:kind,weekly_digest'],
            'due_at' => ['required_unless:kind,weekly_digest', 'nullable', 'date_format:Y-m-d\TH:i:sP', 'after:now', 'prohibited_if:kind,weekly_digest']]);
        $id = $schedules->save((int) $request->user()->id, $request->session()->get('active_org_id'), $data, $schedule);

        return $this->completed($request, ['id' => $id, 'message' => $data['kind'] === 'follow_up_draft' ? 'Schedule saved; follow-up drafts are not enabled yet' : 'Schedule saved; email will be queued when due'], '/automation', $schedule ? 200 : 201);
    }

    public function revoke(Request $request, BusinessSchedules $schedules, string $schedule): JsonResponse|RedirectResponse
    {
        $version = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646']])['version'];
        $schedules->revoke((int) $request->user()->id, $request->session()->get('active_org_id'), $schedule, $version);

        return $this->completed($request, ['message' => 'Schedule consent withdrawn'], '/automation');
    }
}
