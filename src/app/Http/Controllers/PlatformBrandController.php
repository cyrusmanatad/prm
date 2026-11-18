<?php

namespace App\Http\Controllers;

use App\Services\PlatformAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Review of Brand workspace requests (D37). The `brands.review` capability and every rule are enforced in PostgreSQL. */
class PlatformBrandController extends PlatformConsoleController
{
    public function index(Request $request, PlatformAdministration $admin): mixed
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(['pending', 'needs_information', 'approved', 'declined'])],
            'source' => ['nullable', Rule::in(['request', 'rollout'])], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $this->read($request, $admin, 'brands.review', 'PlatformBrandRequests', fn () => [
            'requests' => $this->json('SELECT app_platform_brand_requests(?::jsonb,?) AS data', [$this->filters($filters), $filters['page'] ?? 1]),
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? '', 'source' => $filters['source'] ?? ''], 'page' => (int) ($filters['page'] ?? 1),
            'canDirectory' => $this->allowed('directory.read'),
        ]);
    }

    public function show(Request $request, PlatformAdministration $admin, string $brandRequest): mixed
    {
        return $this->read($request, $admin, 'brands.review', 'PlatformBrandRequest', fn () => [
            ...$this->json('SELECT app_platform_brand_request(?::uuid) AS data', [$brandRequest]), 'canDirectory' => $this->allowed('directory.read'),
        ]);
    }

    /** Approve, request more information or decline. Feedback is required and is shown to the applicant. */
    public function decide(Request $request, PlatformAdministration $admin, string $brandRequest): mixed
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1'], 'decision' => ['required', Rule::in(['approve', 'request_information', 'decline'])],
            'feedback' => ['required', 'string', 'max:1000', 'not_regex:/^\s*$/']]);
        try {
            return $admin->run($request, function () use ($request, $data, $brandRequest) {
                $this->require('brands.review');
                $result = $this->json('SELECT app_platform_brand_decide(?::uuid,?,?,?,?::uuid,?::uuid) AS data', [
                    $brandRequest, $data['expected_version'], $data['decision'], trim($data['feedback']), (string) Str::uuid(), $data['decision'] === 'approve' ? (string) Str::uuid() : null,
                ]);
                $message = ['approved' => 'Request approved. The Brand workspace was created for the applicant.', 'declined' => 'Request declined', 'needs_information' => 'More information requested from the applicant'][$result['status']];

                return $this->completed($request, [...$result, 'message' => $message], '/admin/brand-requests/'.$brandRequest);
            });
        } catch (QueryException $error) {
            $state = $error->errorInfo[0] ?? '';
            abort_if($state === 'P0002', 404);
            abort_if($state === '42501', 403);
            if (in_array($state, ['40001', '40P01'], true)) {
                throw ValidationException::withMessages(['expected_version' => 'This request changed or was already decided. Reload it before deciding.'])->status(409);
            }
            if ($state === '22000') {
                // The database message states the rule that refused the decision; it contains no record data.
                throw ValidationException::withMessages(['feedback' => trim(explode("\n", preg_replace('/^.*ERROR:\s*/s', '', $error->getMessage()))[0]).'.']);
            }
            throw $error;
        }
    }
}
