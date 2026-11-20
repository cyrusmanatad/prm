<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrivacyClosures
{
    public function __construct(private TenantContext $context, private PrivacyExports $identity) {}

    public function page(Request $request, string $scope, ?string $org): array
    {
        return $this->context->run((int) $request->user()->id, null, function () use ($request, $scope, $org) {
            $preview = $scope === 'account' || $org !== null ? $this->json('SELECT app_privacy_preview(?,?::uuid) AS data', [$scope, $org]) : null;

            return ['preview' => $preview, 'requests' => $this->json('SELECT app_privacy_history() AS data'), 'workspaces' => $this->json('SELECT app_privacy_targets() AS data'),
                'enrolled' => DB::table('users')->where('id', $request->user()->id)->value('admin_totp_confirmed_at') !== null,
                'accountClosing' => DB::selectOne('SELECT app_account_closing(?) AS closing', [$request->user()->id])->closing,
                'selectedScope' => $scope, 'selectedOrg' => $org];
        });
    }

    public function close(Request $request, array $data): string
    {
        $this->identity->verify($request, $data);
        $id = (string) Str::uuid();
        $this->change(fn () => $this->context->run((int) $request->user()->id, null, function () use ($request, $data, $id) {
            $this->identity->assertIdentity($request, $data['scope'] === 'workspace');
            DB::select('SELECT app_privacy_close(?::uuid,?,?::uuid,?,?)', [$id, $data['scope'], $data['scope'] === 'workspace' ? $data['org_id'] : null, $data['manifest_hash'], trim($data['reason'])]);
        }, accessBarrier: false));

        return $id;
    }

    public function cancel(Request $request, string $id, array $data): void
    {
        $this->identity->verify($request, $data);
        $this->change(fn () => $this->context->run((int) $request->user()->id, null, function () use ($request, $id, $data) {
            $this->identity->assertIdentity($request);
            DB::select('SELECT app_privacy_cancel(?::uuid,?,?,NULL)', [$id, $data['version'], trim($data['reason'])]);
        }, accessBarrier: false));
    }

    public function change(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $error) {
            $state = $error->errorInfo[0] ?? '';
            if (in_array($state, ['40001', '40P01', '23505'], true)) {
                throw ValidationException::withMessages(['version' => 'The records or request changed. Reload and review the latest details before trying again.'])->status(409);
            }
            if ($state === '42501') {
                abort(403);
            }
            if ($state === 'P0002') {
                abort(404);
            }
            if ($state === '22000') {
                throw ValidationException::withMessages(['closure' => 'This closure action is blocked. Review the latest ownership, request assignment and required fields.']);
            }
            throw $error;
        }
    }

    private function json(string $query, array $bindings = []): array
    {
        return json_decode(DB::selectOne($query, $bindings)->data, true, flags: JSON_THROW_ON_ERROR);
    }
}
