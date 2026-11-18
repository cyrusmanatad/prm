<?php

namespace App\Http\Controllers;

use App\Services\PlatformAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Read-only administration projections and operator grant management. Authorization is enforced in PostgreSQL. */
class PlatformConsoleController extends Controller
{
    protected const CAPABILITIES = ['platform.owner', 'directory.read', 'plans.assign', 'notifications.read', 'notifications.reconcile', 'operations.read', 'audit.read', 'access.manage', 'support.request', 'cleanup.review', 'brands.review'];

    /** Joined/created date range shared by both directories; `to` is inclusive. */
    private const CREATED = ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']];

    public function accounts(Request $request, PlatformAdministration $admin): mixed
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'verified' => ['nullable', Rule::in(['yes', 'no'])], 'grant' => ['nullable', Rule::in(['yes', 'no'])], 'access' => ['nullable', Rule::in(['active', 'suspended'])], ...self::CREATED, 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $this->read($request, $admin, 'directory.read', 'PlatformAccounts', fn () => [
            'accounts' => $this->json('SELECT app_platform_accounts(?::jsonb,?) AS data', [$this->filters($this->created($filters)), $filters['page'] ?? 1]),
            'filters' => ['q' => $filters['q'] ?? '', 'verified' => $filters['verified'] ?? '', 'grant' => $filters['grant'] ?? '', 'access' => $filters['access'] ?? '', 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? ''], 'page' => (int) ($filters['page'] ?? 1),
        ]);
    }

    public function account(Request $request, PlatformAdministration $admin, int $account): mixed
    {
        $tab = $request->validate(['tab' => ['nullable', Rule::in(['overview', 'workspaces', 'security', 'activity'])]])['tab'] ?? 'overview';

        return $this->read($request, $admin, 'directory.read', 'PlatformAccount', fn () => [...$this->json('SELECT app_platform_account(?) AS data', [$account]), 'tab' => $tab,
            // Consequences of an access change, computed by the server; null when the operator cannot manage access.
            'access' => $this->allowed('access.manage') ? $this->json("SELECT app_platform_access_impact('account',?) AS data", [(string) $account]) : null]);
    }

    public function workspaces(Request $request, PlatformAdministration $admin): mixed
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'kind' => ['nullable', Rule::in(['creator', 'brand'])],
            'state' => ['nullable', Rule::in(['active', 'scheduled', 'expired', 'unassigned-development', 'pending-activation'])], 'access' => ['nullable', Rule::in(['active', 'suspended'])], ...self::CREATED, 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $this->read($request, $admin, 'directory.read', 'PlatformWorkspaces', fn () => [
            'workspaces' => $this->json('SELECT app_platform_workspaces(?::jsonb,?) AS data', [$this->filters($this->created($filters)), $filters['page'] ?? 1]),
            'filters' => ['q' => $filters['q'] ?? '', 'kind' => $filters['kind'] ?? '', 'state' => $filters['state'] ?? '', 'access' => $filters['access'] ?? '', 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? ''], 'page' => (int) ($filters['page'] ?? 1),
        ]);
    }

    public function workspace(Request $request, PlatformAdministration $admin, string $workspace): mixed
    {
        $tab = $request->validate(['tab' => ['nullable', Rule::in(['overview', 'members', 'entitlements', 'operations', 'activity'])]])['tab'] ?? 'overview';

        return $this->read($request, $admin, 'directory.read', 'PlatformWorkspace', fn () => [...$this->json('SELECT app_platform_workspace(?::uuid) AS data', [$workspace]), 'tab' => $tab,
            'access' => $this->allowed('access.manage') ? $this->json("SELECT app_platform_access_impact('workspace',?) AS data", [$workspace]) : null,
            // Brand workspaces only: who approved it, when, why and whether through a request or the rollout.
            'approval' => json_decode(DB::selectOne('SELECT app_platform_workspace_approval(?::uuid) AS data', [$workspace])->data ?? 'null', true, flags: JSON_THROW_ON_ERROR),
            'canReviewBrands' => $this->allowed('brands.review')]);
    }

    public function plans(Request $request, PlatformAdministration $admin): mixed
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $this->read($request, $admin, 'plans.assign', 'PlatformPlans', fn () => [
            'plans' => $this->json('SELECT app_platform_plans(?,?) AS data', [$filters['q'] ?? '', $filters['page'] ?? 1]), 'filters' => ['q' => $filters['q'] ?? ''], 'page' => (int) ($filters['page'] ?? 1),
        ]);
    }

    public function plan(Request $request, PlatformAdministration $admin, string $plan): mixed
    {
        return $this->read($request, $admin, 'plans.assign', 'PlatformPlan', fn () => $this->json('SELECT app_platform_plan(?::uuid) AS data', [$plan]));
    }

    public function assignments(Request $request, PlatformAdministration $admin): mixed
    {
        $filters = $request->validate(['workspace' => ['nullable', 'uuid'], 'state' => ['nullable', Rule::in(['active', 'scheduled', 'expired', 'superseded'])], 'history' => ['nullable', Rule::in(['all'])], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $this->read($request, $admin, 'plans.assign', 'PlatformAssignments', fn () => [
            'assignments' => $this->json('SELECT app_platform_assignments(?::jsonb,?) AS data', [$this->filters($filters), $filters['page'] ?? 1]),
            'filters' => ['workspace' => $filters['workspace'] ?? '', 'state' => $filters['state'] ?? '', 'history' => $filters['history'] ?? ''], 'page' => (int) ($filters['page'] ?? 1),
        ]);
    }

    public function assignmentForm(Request $request, PlatformAdministration $admin): mixed
    {
        $data = $request->validate(['workspace' => ['required', 'uuid']]);

        return $this->read($request, $admin, 'plans.assign', 'PlatformAssign', function () use ($data) {
            $this->require('directory.read');
            $detail = $this->json('SELECT app_platform_workspace(?::uuid) AS data', [$data['workspace']]);

            return ['workspace' => $detail['workspace'], 'entitlement' => $detail['entitlement']];
        });
    }

    public function notification(Request $request, PlatformAdministration $admin, string $notification): mixed
    {
        return $this->read($request, $admin, 'notifications.read', 'PlatformNotification', fn () => [
            'notice' => $this->json('SELECT app_platform_notification(?::uuid) AS data', [$notification]), 'canResolve' => $this->allowed('notifications.reconcile'),
        ]);
    }

    public function operations(Request $request, PlatformAdministration $admin): mixed
    {
        return $this->read($request, $admin, 'operations.read', 'PlatformOperations', function () {
            $queue = (string) config('queue.connections.redis.queue', 'default');
            // Redis is the live queue. When it cannot be observed the depth is unknown, never zero.
            try {
                $redis = (bool) Redis::connection()->ping();
                $depth = Queue::connection('redis')->size($queue);
            } catch (\Throwable) {
                $redis = false;
                $depth = null;
            }

            return [...$this->json('SELECT app_platform_operations() AS data'), 'queue' => ['name' => $queue, 'backend' => 'Redis', 'reachable' => $redis, 'pending' => $depth]];
        });
    }

    public function audit(Request $request, PlatformAdministration $admin): mixed
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'source' => ['nullable', Rule::in(['platform', 'local-pilot'])], 'outcome' => ['nullable', Rule::in(['recorded', 'denied'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $query = $this->filters([...$filters, 'to' => isset($filters['to']) ? date('Y-m-d', strtotime($filters['to'].' +1 day')) : null]);

        return $this->read($request, $admin, 'audit.read', 'PlatformAudit', fn () => [
            'events' => $this->json('SELECT app_platform_audit_list(?::jsonb,?) AS data', [$query, $filters['page'] ?? 1]),
            'filters' => ['q' => $filters['q'] ?? '', 'source' => $filters['source'] ?? '', 'outcome' => $filters['outcome'] ?? '', 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? ''], 'page' => (int) ($filters['page'] ?? 1),
        ]);
    }

    public function auditEvent(Request $request, PlatformAdministration $admin, string $source, string $event): mixed
    {
        return $this->read($request, $admin, 'audit.read', 'PlatformAuditEvent', fn () => ['event' => $this->json('SELECT app_platform_audit_event(?,?) AS data', [$source, $event])]);
    }

    public function operators(Request $request, PlatformAdministration $admin): mixed
    {
        $data = $request->validate(['account' => ['nullable', 'integer', 'min:1']]);

        return $this->read($request, $admin, 'platform.owner', 'PlatformOperators', function () use ($data) {
            $candidate = isset($data['account']) ? DB::table('users')->where('id', $data['account'])->first(['id', 'name', 'email', 'email_verified_at']) : null;

            return ['operators' => $this->json('SELECT app_platform_operators() AS data'), 'capabilities' => self::CAPABILITIES,
                'candidate' => $candidate ? ['id' => $candidate->id, 'name' => $candidate->name, 'email' => $candidate->email, 'verified' => $candidate->email_verified_at !== null] : null];
        });
    }

    public function saveOperator(Request $request, PlatformAdministration $admin): mixed
    {
        $data = $request->validate(['user' => ['required', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:0'], 'revoke' => ['required', 'boolean'],
            'capabilities' => ['exclude_if:revoke,true', 'required', 'array', 'min:1'], 'capabilities.*' => ['string', 'distinct', Rule::in(self::CAPABILITIES)],
            'reason' => ['required', 'string', 'max:240', 'not_regex:/^\s*$/'], 'password' => ['required', 'string'], 'code' => ['required', 'regex:/^[0-9]{6}$/D']]);
        try {
            return $admin->run($request, function () use ($request, $admin, $data) {
                $this->require('platform.owner');
                // Changing who holds platform access needs a fresh password and authenticator code, not only the 15-minute step-up.
                $admin->confirm($request, $data['password'], $data['code']);
                DB::selectOne('SELECT app_platform_grant_save(?,?,?::text[],?,?) AS version', [$data['user'], $data['expected_version'], '{'.implode(',', $data['capabilities'] ?? []).'}', $data['revoke'] ? 'true' : 'false', trim($data['reason'])]);

                return $this->completed($request, ['message' => $data['revoke'] ? 'Platform access revoked' : 'Platform access recorded'], '/admin/operators');
            });
        } catch (QueryException $error) {
            $state = $error->errorInfo[0] ?? '';
            // 40P01: two owners changed each other's grants at once and PostgreSQL let only one proceed.
            if (in_array($state, ['40001', '40P01'], true)) {
                throw ValidationException::withMessages(['expected_version' => 'This grant changed. Reload platform access before saving again.'])->status(409);
            }
            if ($state === '22000') {
                $own = str_contains($error->getMessage(), 'their own grant');
                throw ValidationException::withMessages(['user' => $own ? 'You cannot change your own platform grant. Another platform owner or the offline procedure must do it.' : 'Choose a verified account and at least one capability.']);
            }
            throw $error;
        }
    }

    protected function read(Request $request, PlatformAdministration $admin, string $permission, string $page, \Closure $props): mixed
    {
        try {
            return $admin->run($request, function () use ($request, $permission, $page, $props) {
                $this->require($permission);
                $payload = $props();

                return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render($page, $payload);
            });
        } catch (QueryException $error) {
            abort_if(($error->errorInfo[0] ?? '') === 'P0002', 404);
            abort_if(($error->errorInfo[0] ?? '') === '42501', 403);
            throw $error;
        }
    }

    protected function allowed(string $permission): bool
    {
        return (bool) DB::selectOne('SELECT app_platform_allowed(?) AS ok', [$permission])->ok;
    }

    protected function require(string $permission): void
    {
        abort_unless($this->allowed($permission), 403);
    }

    protected function json(string $sql, array $bindings = []): array
    {
        return json_decode(DB::selectOne($sql, $bindings)->data, true, flags: JSON_THROW_ON_ERROR);
    }

    /** Translates the inclusive date range into the half-open range the directory functions expect. */
    protected function created(array $filters): array
    {
        $range = ['created_from' => $filters['from'] ?? null, 'created_to' => isset($filters['to']) ? date('Y-m-d', strtotime($filters['to'].' +1 day')) : null];

        return [...array_diff_key($filters, ['from' => 1, 'to' => 1]), ...$range];
    }

    protected function filters(array $filters): string
    {
        unset($filters['page']);

        return json_encode((object) array_filter($filters, fn ($value) => $value !== null && $value !== ''), JSON_THROW_ON_ERROR);
    }
}
