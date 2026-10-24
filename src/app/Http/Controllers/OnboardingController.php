<?php

namespace App\Http\Controllers;

use App\Services\Onboarding;
use App\Services\OnboardingDocuments;
use App\Services\Pipeline;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\HeaderUtils;

class OnboardingController extends Controller
{
    public function index(Request $request, Onboarding $service, Pipeline $pipeline): mixed
    {
        if ($request->is('onboarding-requests') && (! $request->expectsJson() || $request->header('X-Inertia'))) {
            return redirect('/partner/onboarding'.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }

        $org = $request->session()->get('active_org_id');
        $payload = ['workspace' => ['id' => $org, 'name' => DB::table('organizations')->where('id', $org)->value('name')],
            'canWrite' => $pipeline->canWrite((int) $request->user()->id, $org),
            'requests' => DB::table('onboarding_requests')->where('org_id', $org)->orderByDesc('created_at')->limit(100)->get(['id', 'label', 'status', 'version', 'pipeline_entry_id'])->all(),
            'issuedLink' => $request->session()->has('issued_link') ? Crypt::decryptString($request->session()->get('issued_link')) : null];
        $id = $request->validate(['request' => ['nullable', 'uuid']])['request'] ?? null;
        $payload['selected'] = $id ? $service->detail($org, $id) : null;
        $payload['emails'] = $id ? DB::table('notification_outbox')->where('org_id', $org)->where('onboarding_request_id', $id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get(['id', 'state', 'created_at'])->all() : [];
        $payload['submissions'] = $id ? DB::table('onboarding_submissions')->where('org_id', $org)->where('request_id', $id)->orderByDesc('revision')->get(['id', 'revision', 'snapshot', 'consent_at'])->map(fn ($r) => [...(array) $r, 'snapshot' => json_decode($r->snapshot, true)])->all() : [];

        return $this->page($request, 'OnboardingAdmin', $payload);
    }

    public function store(Request $request, Onboarding $service): mixed
    {
        $data = $request->validate(['pipeline_entry_id' => ['required', 'uuid'], 'label' => ['required', 'string', 'max:160']]);
        $result = $service->create((int) $request->user()->id, $request->session()->get('active_org_id'), $data['pipeline_entry_id'], $data['label'], $this->recipient($request));

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($result, 201) : redirect('/partner/onboarding?request='.$result['id'])->with('issued_link', Crypt::encryptString($result['url']))->with('status', 'Invitation link created. Copy it now; it is shown once.');
    }

    public function review(Request $request, Onboarding $service, string $record, string $action): mixed
    {
        $d = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646'], 'note' => [$action === 'correct' ? 'required' : 'nullable', 'string', 'max:2000']]);
        if ($action === 'renew') {
            $result = $service->renew((int) $request->user()->id, $request->session()->get('active_org_id'), $record, (int) $d['version'], $this->recipient($request));

            return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($result) : redirect('/partner/onboarding?request='.$record)->with('issued_link', Crypt::encryptString($result['url']))->with('status', 'New invitation created. Previous sessions are closed.');
        }
        $service->review((int) $request->user()->id, $request->session()->get('active_org_id'), $record, (int) $d['version'], $action, $d['note'] ?? '');

        return $this->completed($request, ['message' => 'Onboarding '.$action.' saved.'], '/partner/onboarding?request='.$record);
    }

    public function exchange(Request $request, Onboarding $service): mixed
    {
        $token = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']])['token'];
        $secret = $service->exchange($token);
        $id = $service->publicRun($secret, fn ($org, $id) => $id);
        $request->session()->regenerate();
        $request->session()->put('onboarding.'.$id, Crypt::encryptString($secret));

        return $this->completed($request, ['message' => 'Invitation opened.', 'id' => $id], '/onboarding/form/'.$id);
    }

    public function form(Request $request, Onboarding $service, string $record): mixed
    {
        return $this->scoped($request, $service, $record, fn ($org, $id) => $this->page($request, 'OnboardingForm', ['form' => $service->detail($org, $id)]));
    }

    public function save(Request $request, Onboarding $service, string $record): mixed
    {
        $data = $this->details($request, false);
        $version = $this->version($request);

        return $this->scoped($request, $service, $record, function ($org, $id) use ($request, $service, $data, $version) {
            $service->save($org, $id, $data, $version);

            return $this->completed($request, ['message' => 'Draft saved.'], '/onboarding/form/'.$id);
        });
    }

    public function submit(Request $request, Onboarding $service, string $record): mixed
    {
        $data = $this->details($request, true);
        $version = $this->version($request);
        $key = $request->validate(['idempotency_key' => ['required', 'uuid'], 'consent' => ['required', 'accepted']])['idempotency_key'];

        return $this->scoped($request, $service, $record, function ($org, $id) use ($request, $service, $data, $version, $key) {
            $submission = $service->submit($org, $id, $version, $key, $data);

            return $this->completed($request, ['message' => 'Form submitted for review.', 'submission_id' => $submission], '/onboarding/form/'.$id);
        });
    }

    public function upload(Request $request, Onboarding $service, OnboardingDocuments $docs, string $record): mixed
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimetypes:application/pdf,image/png,image/jpeg,text/plain']]);
        $version = $this->version($request);

        return $this->scoped($request, $service, $record, function ($org, $id) use ($request, $docs, $version) {
            $doc = $docs->upload($org, $id, $version, $request->file('file'));

            return $this->completed($request, ['message' => 'Upload received. Check its scan status before submitting.', 'id' => $doc], '/onboarding/form/'.$id, 201);
        });
    }

    public function document(Request $request, Onboarding $service, OnboardingDocuments $docs, string $record, string $document): mixed
    {
        return $this->scoped($request, $service, $record, fn ($org, $id) => $this->download($docs, $org, $id, $document));
    }

    public function removeDocument(Request $request, Onboarding $service, OnboardingDocuments $docs, string $record, string $document): mixed
    {
        $version = $this->version($request);

        return $this->scoped($request, $service, $record, function ($org, $id) use ($request, $docs, $document, $version) {
            $docs->remove($org, $id, $document, $version);

            return $this->completed($request, ['message' => 'File removed.'], '/onboarding/form/'.$id);
        });
    }

    public function ownerDocument(Request $request, OnboardingDocuments $docs, Pipeline $pipeline, string $record, string $document): mixed
    {
        $org = $request->session()->get('active_org_id');
        if ($request->isMethod('post')) {
            abort_unless($pipeline->canWrite((int) $request->user()->id, $org), 403);
            $docs->scan($org, $record, $document);

            return $this->completed($request, ['message' => 'Scan attempted.'], '/partner/onboarding?request='.$record);
        }

        return $this->download($docs, $org, $record, $document);
    }

    private function download(OnboardingDocuments $docs, string $org, string $id, string $document): mixed
    {
        [$bytes,$name] = $docs->download($org, $id, $document);

        return response($bytes)->header('Content-Type', 'application/octet-stream')->header('Content-Disposition', HeaderUtils::makeDisposition('attachment', $name, 'attachment'))
            ->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', "sandbox; default-src 'none'");
    }

    private function scoped(Request $request, Onboarding $service, string $id, Closure $callback): mixed
    {
        $secret = $request->session()->get('onboarding.'.$id);
        abort_unless(is_string($secret), 403);

        try {
            $secret = Crypt::decryptString($secret);
        } catch (DecryptException) {
            abort(403);
        }

        return $service->publicRun($secret, function ($org, $resolved) use ($id, $callback) {
            abort_unless($resolved === $id, 403);

            return $callback($org, $id);
        });
    }

    private function details(Request $request, bool $submit): array
    {
        $d = $request->validate(['business_name' => [$submit ? 'required' : 'nullable', 'string', 'max:160'], 'contact_name' => [$submit ? 'required' : 'nullable', 'string', 'max:120'],
            'business_email' => [$submit ? 'required' : 'nullable', 'email', 'max:255'], 'website' => ['nullable', 'url:http,https', 'max:500'], 'brief' => [$submit ? 'required' : 'nullable', 'string', 'max:5000']]);

        return array_map(fn ($v) => $v ?? '', array_replace(array_fill_keys(['business_name', 'contact_name', 'business_email', 'website', 'brief'], ''), $d));
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646']])['version'];
    }

    private function recipient(Request $request): ?string
    {
        $data = $request->validate([
            'send_email' => ['sometimes', 'boolean'],
            'recipient_email' => ['exclude_unless:send_email,true', 'required', 'string', 'email:rfc', 'max:255'],
        ]);

        return ($data['send_email'] ?? false) ? $data['recipient_email'] : null;
    }

    private function page(Request $request, string $page, array $props): mixed
    {
        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($props) : Inertia::render($page, $props);
    }
}
