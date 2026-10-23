<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Onboarding
{
    public function __construct(private Pipeline $pipeline, private Audit $audit, private TenantContext $context) {}

    public function create(int $actor, string $org, string $entry, string $label): array
    {
        abort_unless($this->pipeline->canWrite($actor, $org), 403);
        abort_unless(DB::table('pipeline_entries')->where('org_id', $org)->where('id', $entry)->lockForUpdate()->first(), 404);
        $id = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        DB::table('onboarding_requests')->insert(['id' => $id, 'org_id' => $org, 'pipeline_entry_id' => $entry, 'label' => $label, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);
        $this->audit->record($actor, $org, 'onboarding.started', $id);

        return ['id' => $id, 'url' => url('/onboarding').'#token='.$token];
    }

    public function renew(int $actor, string $org, string $id, int $version): array
    {
        abort_unless($this->pipeline->canWrite($actor, $org), 403);
        $row = $this->locked($org, $id, $version);
        abort_if($row->status === 'revoked', 409);
        $token = bin2hex(random_bytes(32));
        DB::table('onboarding_sessions')->where('org_id', $org)->where('request_id', $id)->delete();
        DB::table('onboarding_requests')->where('org_id', $org)->where('id', $id)->update(['token_hash' => hash('sha256', $token), 'consumed_at' => null, 'expires_at' => now()->addDays(7), 'version' => $version + 1]);
        $this->audit->record($actor, $org, 'onboarding.renewed', $id);

        return ['id' => $id, 'url' => url('/onboarding').'#token='.$token];
    }

    public function exchange(string $token): string
    {
        $session = bin2hex(random_bytes(32));
        $ok = DB::selectOne('SELECT app_exchange_onboarding(?,?,?::uuid) AS ok', [hash('sha256', $token), hash('sha256', $session), (string) Str::uuid()]);
        abort_unless($ok?->ok, 404);

        return $session;
    }

    public function publicRun(string $secret, Closure $callback): mixed
    {
        return $this->context->run(0, null, function () use ($secret, $callback) {
            $hash = hash('sha256', $secret);
            $scope = DB::selectOne('SELECT * FROM app_onboarding_scope(?)', [$hash]);
            abort_unless($scope, 403);
            DB::selectOne("SELECT set_config('app.capability',?,true),set_config('app.org_id',?,true)", [$hash, $scope->org]);

            return $callback($scope->org, $scope->request);
        }, requireMembership: false);
    }

    public function detail(string $org, string $id): array
    {
        $r = DB::table('onboarding_requests')->where('org_id', $org)->where('id', $id)->first();
        abort_unless($r, 404);

        return ['id' => $r->id, 'label' => $r->label, 'status' => $r->status, 'business_name' => $r->business_name, 'contact_name' => $r->contact_name,
            'business_email' => $r->business_email, 'website' => $r->website, 'brief' => $r->brief, 'correction_note' => $r->correction_note, 'version' => $r->version,
            'documents' => DB::table('onboarding_documents')->where('org_id', $org)->where('request_id', $id)->get(['id', 'name', 'size', 'scan_state'])->all()];
    }

    public function save(string $org, string $id, array $data, int $version): void
    {
        $r = $this->locked($org, $id, $version);
        abort_unless(in_array($r->status, ['draft', 'correction'], true), 409);
        DB::table('onboarding_requests')->where('org_id', $org)->where('id', $id)->update([...$data, 'version' => $version + 1]);
        $this->event($org, $id, 'draft.saved');
    }

    public function submit(string $org, string $id, int $version, string $key, array $data): string
    {
        $hash = hash('sha256', json_encode(['version' => $version, ...$data], JSON_THROW_ON_ERROR));
        $query = DB::table('onboarding_requests')->where('org_id', $org)->where('id', $id);
        $r = (clone $query)->lockForUpdate()->first();
        abort_unless($r, 404);
        $prior = DB::table('onboarding_submissions')->where('org_id', $org)->where('request_id', $id)->where('idempotency_key', $key)->first();
        if ($prior) {
            abort_unless(hash_equals($prior->payload_hash, $hash), 409);

            return $prior->id;
        }
        $this->checkVersion($r->version, $version);
        abort_unless(in_array($r->status, ['draft', 'correction'], true), 409);
        $documents = DB::table('onboarding_documents')->where('org_id', $org)->where('request_id', $id)->get(['id', 'checksum', 'scan_state']);
        if ($documents->contains(fn ($d) => $d->scan_state !== 'clean')) {
            throw ValidationException::withMessages(['file' => 'Remove unsafe files or wait for a clean scan before submitting.']);
        }
        $submission = (string) Str::uuid();
        DB::table('onboarding_submissions')->insert(['id' => $submission, 'org_id' => $org, 'request_id' => $id, 'revision' => $version,
            'snapshot' => json_encode([...$data, 'documents' => $documents->map(fn ($d) => ['id' => $d->id, 'checksum' => $d->checksum])->all()], JSON_THROW_ON_ERROR),
            'idempotency_key' => $key, 'payload_hash' => $hash]);
        $query->update([...$data, 'status' => 'submitted', 'version' => $version + 1]);
        $this->event($org, $id, 'submitted');

        return $submission;
    }

    public function review(int $actor, string $org, string $id, int $version, string $action, string $note): void
    {
        abort_unless($this->pipeline->canWrite($actor, $org), 403);
        $r = $this->locked($org, $id, $version);
        if ($action !== 'revoke') {
            abort_unless($r->status === 'submitted', 409);
        }
        DB::table('onboarding_requests')->where('org_id', $org)->where('id', $id)->update(['status' => match ($action) {
            'revoke' => 'revoked','correct' => 'correction','accept' => 'accepted'
        }, 'correction_note' => $note, 'version' => $version + 1]);
        $this->event($org, $id, $action);
        $this->audit->record($actor, $org, 'onboarding.'.$action, $id);
    }

    public function locked(string $org, string $id, int $version): object
    {
        $row = DB::table('onboarding_requests')->where('org_id', $org)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404);
        $this->checkVersion($row->version, $version);

        return $row;
    }

    private function checkVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw ValidationException::withMessages(['version' => 'This form changed. Reload the latest form before saving.'])->status(409);
        }
    }

    private function event(string $org, string $id, string $kind): void
    {
        DB::table('onboarding_events')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'request_id' => $id, 'kind' => $kind]);
    }
}
