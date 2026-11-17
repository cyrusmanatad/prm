<?php

namespace App\Http\Controllers;

use App\Services\PlatformAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Access changes, consented support access and cleanup dry runs. Rules are enforced in PostgreSQL. */
class PlatformGovernanceController extends PlatformConsoleController
{
    private const AREAS = ['pipeline_entries', 'brand_contacts', 'reminders', 'onboarding_requests', 'outreach_templates', 'finance'];

    private const VIEWS = ['pipeline_entries', 'brand_contacts', 'reminders', 'onboarding_requests', 'outreach_templates', 'invoice_drafts', 'manual_payments'];

    /** Suspend or reactivate an account or workspace. Needs a reason, the reviewed version and fresh proof. */
    public function access(Request $request, PlatformAdministration $admin, string $kind, string $reference, string $action): mixed
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:240', 'not_regex:/^\s*$/'], 'password' => ['required', 'string'], 'code' => ['required', 'regex:/^[0-9]{6}$/D']]);
        $subject = $kind === 'accounts' ? 'account' : 'workspace';

        return $this->change($request, $admin, 'access.manage', $data, function () use ($request, $subject, $reference, $action, $data, $kind) {
            DB::select('SELECT '.($action === 'suspend' ? 'app_platform_suspend' : 'app_platform_reactivate').'(?,?,?,?)', [$subject, $reference, $data['expected_version'], trim($data['reason'])]);

            return $this->completed($request, ['message' => ucfirst($subject).($action === 'suspend' ? ' suspended' : ' reactivated')], '/admin/'.$kind.'/'.$reference);
        }, 'This '.$subject.'\'s access changed. Reload it before trying again.');
    }

    public function signOut(Request $request, PlatformAdministration $admin, int $account): mixed
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:240', 'not_regex:/^\s*$/'], 'password' => ['required', 'string'], 'code' => ['required', 'regex:/^[0-9]{6}$/D']]);

        return $this->change($request, $admin, 'access.manage', $data, function () use ($request, $account, $data) {
            $ended = DB::selectOne('SELECT app_platform_sign_out(?,?) AS ended', [$account, trim($data['reason'])])->ended;

            return $this->completed($request, ['ended' => $ended, 'message' => $ended.' '.Str::plural('session', $ended).' signed out'], '/admin/accounts/'.$account.'?tab=security');
        }, 'This account changed. Reload it before trying again.');
    }

    public function support(Request $request, PlatformAdministration $admin): mixed
    {
        $filters = $request->validate(['state' => ['nullable', Rule::in(['requested', 'approved', 'declined', 'revoked', 'cancelled', 'expired'])], 'workspace' => ['nullable', 'uuid'], 'request' => ['nullable', 'uuid'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $this->read($request, $admin, 'support.request', 'PlatformSupport', function () use ($filters) {
            $target = isset($filters['request']) ? DB::table('organizations')->where('id', $filters['request'])->first(['id', 'name']) : null;

            return ['grants' => $this->json('SELECT app_platform_support_list(?::jsonb,?) AS data', [$this->filters(array_diff_key($filters, ['request' => 1])), $filters['page'] ?? 1]),
                'filters' => ['state' => $filters['state'] ?? '', 'workspace' => $filters['workspace'] ?? ''], 'page' => (int) ($filters['page'] ?? 1), 'areas' => self::AREAS,
                'target' => $target && $this->allowed('directory.read') ? ['id' => $target->id, 'name' => $target->name, 'suspended' => (bool) DB::selectOne('SELECT app_workspace_suspended(?::uuid) AS s', [$target->id])->s] : null];
        });
    }

    public function requestSupport(Request $request, PlatformAdministration $admin): mixed
    {
        $data = $request->validate(['workspace' => ['required', 'uuid'], 'purpose' => ['required', 'string', 'max:240', 'not_regex:/^\s*$/'], 'hours' => ['required', 'integer', 'min:1', 'max:24'],
            'resources' => ['required', 'array', 'min:1'], 'resources.*' => ['string', 'distinct', Rule::in(self::AREAS)]]);

        return $this->change($request, $admin, 'support.request', null, function () use ($request, $data) {
            $id = (string) Str::uuid();
            DB::select('SELECT app_support_request(?::uuid,?::uuid,?,?::text[],?)', [$id, $data['workspace'], trim($data['purpose']), '{'.implode(',', $data['resources']).'}', $data['hours']]);

            return $this->completed($request, ['id' => $id, 'message' => 'Support request sent to the workspace owners'], '/admin/support-access/'.$id, 201);
        }, 'You already have an open request or grant for this workspace.', 'workspace');
    }

    public function supportGrant(Request $request, PlatformAdministration $admin, string $grant): mixed
    {
        $data = $request->validate(['area' => ['nullable', Rule::in(self::VIEWS)], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $this->read($request, $admin, 'support.request', 'PlatformSupportGrant', function () use ($grant, $data) {
            $detail = $this->json('SELECT app_platform_support_grant(?::uuid) AS data', [$grant]);
            $area = $data['area'] ?? null;
            // Records are read only through the grant check, which also writes the view to both audit trails.
            $records = $area && $detail['mine'] && $detail['state'] === 'approved' ? $this->json('SELECT app_support_records(?::uuid,?,?) AS data', [$grant, $area, $data['page'] ?? 1]) : null;

            return ['grant' => $detail, 'area' => $records ? $area : null, 'records' => $records, 'page' => (int) ($data['page'] ?? 1)];
        });
    }

    public function endSupport(Request $request, PlatformAdministration $admin, string $grant): mixed
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:240', 'not_regex:/^\s*$/']]);

        return $this->change($request, $admin, 'support.request', null, function () use ($request, $grant, $data) {
            DB::select('SELECT app_platform_support_end(?::uuid,?,?)', [$grant, $data['expected_version'], trim($data['reason'])]);

            return $this->completed($request, ['message' => 'Support access ended'], '/admin/support-access/'.$grant);
        }, 'This support grant changed. Reload it before trying again.');
    }

    /** Workspace owners review, approve, decline or end support access to the workspace they have selected. */
    public function workspaceSupport(Request $request): mixed
    {
        try {
            $requests = json_decode(DB::selectOne('SELECT app_workspace_support_requests() AS data')->data, true, flags: JSON_THROW_ON_ERROR);
        } catch (QueryException $error) {
            abort_if(($error->errorInfo[0] ?? '') === '42501', 403);
            throw $error;
        }
        $payload = ['requests' => $requests, 'workspace' => DB::table('organizations')->where('id', $request->session()->get('active_org_id'))->value('name')];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('WorkspaceSupport', $payload);
    }

    public function decideSupport(Request $request, string $grant): mixed
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1'], 'decision' => ['required', Rule::in(['approve', 'decline', 'end'])]]);
        try {
            DB::select('SELECT app_workspace_support_decide(?::uuid,?,?)', [$grant, $data['expected_version'], $data['decision']]);
        } catch (QueryException $error) {
            $state = $error->errorInfo[0] ?? '';
            abort_if($state === '42501', 403);
            abort_if($state === 'P0002', 404);
            if ($state === '40001') {
                throw ValidationException::withMessages(['expected_version' => 'This request changed. Reload it before deciding.'])->status(409);
            }
            throw $error;
        }

        return $this->completed($request, ['message' => ['approve' => 'Support access approved', 'decline' => 'Support request declined', 'end' => 'Support access ended'][$data['decision']]], '/workspace/support-access');
    }

    public function cleanup(Request $request, PlatformAdministration $admin): mixed
    {
        return $this->read($request, $admin, 'cleanup.review', 'PlatformCleanup', fn () => ['batches' => $this->json('SELECT app_cleanup_batches() AS data')]);
    }

    public function createBatch(Request $request, PlatformAdministration $admin): mixed
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120', 'not_regex:/^\s*$/'], 'reason' => ['required', 'string', 'max:240', 'not_regex:/^\s*$/']]);

        return $this->change($request, $admin, 'cleanup.review', null, function () use ($request, $data) {
            $id = (string) Str::uuid();
            DB::select('SELECT app_cleanup_batch_create(?::uuid,?,?)', [$id, trim($data['name']), trim($data['reason'])]);

            return $this->completed($request, ['id' => $id, 'message' => 'Batch created'], '/admin/cleanup/'.$id, 201);
        }, 'A batch with this name already exists.', 'name');
    }

    public function batch(Request $request, PlatformAdministration $admin, string $batch): mixed
    {
        return $this->read($request, $admin, 'cleanup.review', 'PlatformCleanupBatch', fn () => $this->json('SELECT app_cleanup_batch(?::uuid) AS data', [$batch]));
    }

    public function mark(Request $request, PlatformAdministration $admin, string $batch): mixed
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1'], 'type' => ['required', Rule::in(['account', 'workspace'])], 'reference' => ['required', 'string', 'max:36'],
            'remove' => ['required', 'boolean'], 'evidence' => ['exclude_if:remove,true', 'required', 'string', 'max:240', 'not_regex:/^\s*$/']]);

        return $this->change($request, $admin, 'cleanup.review', null, function () use ($request, $batch, $data) {
            DB::select('SELECT app_cleanup_mark(?::uuid,?,?,?,?,?)', [$batch, $data['expected_version'], $data['type'], strtolower(trim($data['reference'])), $data['evidence'] ?? null, $data['remove'] ? 'true' : 'false']);

            return $this->completed($request, ['message' => $data['remove'] ? 'Record removed from the batch' : 'Record marked'], '/admin/cleanup/'.$batch);
        }, 'This batch changed, or the record is already in that state. Reload the batch.', 'reference');
    }

    public function dryRun(Request $request, PlatformAdministration $admin, string $batch): mixed
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        return $this->change($request, $admin, 'cleanup.review', null, function () use ($request, $batch, $data) {
            $id = (string) Str::uuid();
            DB::select('SELECT app_cleanup_dry_run(?::uuid,?::uuid,?)', [$id, $batch, $data['expected_version']]);

            return $this->completed($request, ['id' => $id, 'message' => 'Dry run recorded; no data deleted'], '/admin/cleanup/runs/'.$id, 201);
        }, 'This batch changed. Reload it before running again.');
    }

    public function run(Request $request, PlatformAdministration $admin, string $run): mixed
    {
        return $this->read($request, $admin, 'cleanup.review', 'PlatformCleanupRun', fn () => ['run' => $this->json('SELECT app_cleanup_run(?::uuid) AS data', [$run])]);
    }

    /** Shared mutation wrapper: capability, optional fresh proof, and consistent conflict/validation responses. */
    private function change(Request $request, PlatformAdministration $admin, string $permission, ?array $proof, \Closure $action, string $conflict, string $field = 'reason'): mixed
    {
        try {
            return $admin->run($request, function () use ($request, $admin, $permission, $proof, $action) {
                $this->require($permission);
                if ($proof) {
                    $admin->confirm($request, $proof['password'], $proof['code']);
                }

                return $action();
            });
        } catch (QueryException $error) {
            $state = $error->errorInfo[0] ?? '';
            abort_if($state === 'P0002', 404);
            abort_if($state === '42501', 403);
            if (in_array($state, ['40001', '40P01'], true)) {
                throw ValidationException::withMessages(['expected_version' => $conflict])->status(409);
            }
            if ($state === '22000') {
                // The database message states the rule that refused the change; it contains no record data.
                throw ValidationException::withMessages([$field => trim(explode("\n", preg_replace('/^.*ERROR:\s*/s', '', $error->getMessage()))[0]).'.']);
            }
            throw $error;
        }
    }
}
