<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PrivacyExports
{
    public function __construct(private TenantContext $context, private Authenticator $authenticator, private PrivacyExportData $data, private PrivacyExportArchive $archive) {}

    public function listing(Request $request): array
    {
        $actor = (int) $request->user()->id;

        return $this->context->run($actor, null, function () use ($actor, $request) {
            $user = $this->account($actor);
            $workspaces = DB::table('organizations')->join('memberships', 'memberships.org_id', '=', 'organizations.id')
                ->where('memberships.user_id', $actor)->where('memberships.role', 'owner')->whereNull('memberships.revoked_at')
                ->orderBy('organizations.id')->get(['organizations.id', 'organizations.name'])->all();
            $exports = DB::table('privacy_exports')->where('actor_id', $actor)->orderByDesc('created_at')->orderByDesc('id')->limit(20)
                ->get(['id', 'scope', 'org_id', 'state', 'size', 'created_at', 'expires_at'])->map(function ($row) {
                    if (now()->gte($row->expires_at)) {
                        $row->state = 'expired';
                    }

                    return $row;
                })->all();

            return ['workspaces' => $workspaces, 'exports' => $exports, 'enrolled' => $user->admin_totp_confirmed_at !== null, 'verified' => $this->proven($request, $user)];
        });
    }

    /** Password plus a fresh, single-use authenticator code when enrolled, independent of staging enforcement. */
    public function verify(Request $request, array $credentials): void
    {
        $actor = (int) $request->user()->id;
        $this->authenticator->guard($actor, 'password');
        $result = $this->context->run($actor, null, function () use ($actor, $credentials) {
            $user = $this->account($actor, true);
            if (! Hash::check($credentials['password'], $user->password)) {
                return ['field' => 'password', 'message' => 'Enter your current password.'];
            }
            if ($user->admin_totp_confirmed_at !== null) {
                $code = $credentials['code'] ?? '';
                $counter = preg_match('/^[0-9]{6}$/D', $code) ? $this->authenticator->match(decrypt($user->admin_totp_secret), $user->admin_totp_counter, $code) : null;
                if ($counter === null) {
                    return ['field' => 'code', 'message' => 'Enter a fresh authenticator code.'];
                }
                DB::table('users')->where('id', $actor)->update(['admin_totp_counter' => $counter]);
            }

            return ['basis' => $this->basis($user)];
        });
        if (isset($result['field'])) {
            $this->authenticator->failed($actor);
            throw ValidationException::withMessages([$result['field'] => $result['message']]);
        }
        $this->authenticator->passed($actor);
        $request->session()->regenerate();
        $request->session()->put('privacy_verified', ['actor' => $actor, 'at' => time(), 'basis' => $result['basis']]);
    }

    public function create(Request $request, ?string $org): string
    {
        $actor = (int) $request->user()->id;
        $id = (string) Str::uuid();
        // Commit the receipt before touching files, so a crash/rollback always leaves a cleanup target.
        $this->context->run($actor, $org, function () use ($actor, $org, $id, $request) {
            $this->authorize($request, $actor, $org);
            DB::table('privacy_exports')->insert(['id' => $id, 'actor_id' => $actor, 'scope' => $org ? 'workspace' : 'account', 'org_id' => $org]);
            $this->event($actor, $id, 'requested');
        });
        try {
            $this->context->run($actor, $org, function () use ($actor, $org, $id, $request) {
                DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['privacy-export:'.$id]);
                $this->authorize($request, $actor, $org);
                $receipt = DB::table('privacy_exports')->where('id', $id)->lockForUpdate()->first();
                abort_unless($receipt && $receipt->state === 'building' && now()->lt($receipt->expires_at), 409);
                $data = $this->data->collect($actor, $org);
                $artifact = $this->archive->write($id, $org ? 'workspace' : 'account', $data);
                DB::table('privacy_exports')->where('id', $id)->update([...$artifact, 'state' => 'ready', 'fingerprint' => $this->fingerprint($data)]);
                $this->event($actor, $id, 'ready');
            }, snapshot: true);
        } catch (Throwable $exception) {
            $this->context->run($actor, null, function () use ($actor, $id) {
                if (DB::table('privacy_exports')->where('id', $id)->where('state', 'building')->update(['state' => 'failed'])) {
                    $this->event($actor, $id, 'failed');
                }
            });
            throw $exception;
        }

        return $id;
    }

    public function download(Request $request, string $id): string
    {
        $actor = (int) $request->user()->id;
        $org = $this->context->run($actor, null, function () use ($actor, $id) {
            $receipt = DB::table('privacy_exports')->where('actor_id', $actor)->where('id', $id)->first();
            abort_unless($receipt, 404);

            return $receipt->org_id;
        });

        return $this->context->run($actor, $org, function () use ($actor, $org, $id, $request) {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['privacy-export:'.$id]);
            $this->authorize($request, $actor, $org);
            $receipt = DB::table('privacy_exports')->where('id', $id)->where('actor_id', $actor)->lockForUpdate()->first();
            abort_unless($receipt && $receipt->state === 'ready' && now()->lt($receipt->expires_at), 410, 'This export is unavailable or expired. Create a new export.');
            $current = $this->data->collect($actor, $org);
            abort_unless(hash_equals($receipt->fingerprint, $this->fingerprint($current)), 409, 'Included data or access changed. Create a fresh export.');
            $disk = Storage::disk('privacy-exports');
            abort_unless($disk->exists($id.'.zip') && $disk->size($id.'.zip') === (int) $receipt->size && (int) $receipt->size <= PrivacyExportArchive::MAX_BYTES + 1024 * 1024, 409);
            $bytes = $disk->get($id.'.zip');
            abort_unless(hash_equals($receipt->checksum, hash('sha256', $bytes)), 409);
            $this->event($actor, $id, 'downloaded');

            return $bytes;
        }, snapshot: true);
    }

    /** Retryable cleanup: only mark expired after both final and interrupted files are confirmed absent. */
    public function purge(): array
    {
        $removed = $failed = 0;
        foreach (DB::select('SELECT id FROM app_privacy_export_expired()') as $row) {
            try {
                $removed += DB::transaction(function () use ($row) {
                    if (! DB::selectOne('SELECT app_privacy_export_cleanup(?::uuid,false) AS allowed', [$row->id])->allowed) {
                        return 0;
                    }
                    $disk = Storage::disk('privacy-exports');
                    foreach ([$row->id.'.zip', $row->id.'.part.zip'] as $key) {
                        if ($disk->exists($key)) {
                            $disk->delete($key);
                        }
                        if ($disk->exists($key)) {
                            throw new \RuntimeException('Archive cleanup incomplete.');
                        }
                    }
                    abort_unless(DB::selectOne('SELECT app_privacy_export_cleanup(?::uuid,true) AS done', [$row->id])->done, 409);

                    return 1;
                });
            } catch (Throwable) {
                $failed++;
            }
        }

        return ['removed' => $removed, 'failed' => $failed];
    }

    private function authorize(Request $request, int $actor, ?string $org): void
    {
        $user = $this->account($actor);
        if (! $this->proven($request, $user)) {
            throw ValidationException::withMessages(['verification' => 'Verify your password and authenticator again to continue.']);
        }
        if ($org !== null) {
            abort_unless($user->admin_totp_confirmed_at !== null && DB::selectOne("SELECT app_has_membership(?::uuid,ARRAY['owner']) AS allowed", [$org])->allowed, 403);
        }
    }

    private function account(int $actor, bool $lock = false): object
    {
        $query = DB::table('users')->where('id', $actor);
        $user = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($user && $user->email_verified_at !== null && ! DB::selectOne('SELECT app_account_suspended(?) AS suspended', [$actor])->suspended, 403);

        return $user;
    }

    private function proven(Request $request, object $user): bool
    {
        $proof = $request->session()->get('privacy_verified');

        return is_array($proof) && ($proof['actor'] ?? null) === (int) $user->id && ($proof['at'] ?? 0) > time() - 900
            && hash_equals($this->basis($user), $proof['basis'] ?? '');
    }

    private function basis(object $user): string
    {
        return hash('sha256', json_encode([$user->id, $user->email, $user->email_verified_at, $user->password, $user->admin_totp_secret, $user->admin_totp_confirmed_at], JSON_THROW_ON_ERROR));
    }

    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function event(int $actor, string $id, string $event): void
    {
        DB::table('privacy_export_events')->insert(['id' => (string) Str::uuid(), 'export_id' => $id, 'actor_id' => $actor, 'event' => $event]);
    }
}
