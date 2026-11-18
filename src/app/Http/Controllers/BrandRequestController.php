<?php

namespace App\Http\Controllers;

use App\Services\BrandRequests;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Applicant side of Brand workspace approval (D37). An account sees and changes only its own requests. */
class BrandRequestController extends Controller
{
    /** The address is applicant-supplied information for the reviewer. The server never fetches it. */
    private const RULES = [
        'brand_name' => ['required', 'string', 'max:120', 'not_regex:/^\s*$/'],
        'website' => ['required', 'string', 'max:500', 'url:http,https', 'not_regex:/\s/'],
        'applicant_role' => ['required', 'string', 'max:120', 'not_regex:/^\s*$/'],
        'intended_use' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/'],
    ];

    private const MESSAGES = [
        'website.url' => 'Enter a full web address starting with http:// or https://.',
        'website.not_regex' => 'Enter a web address without spaces.',
    ];

    public function index(Request $request, BrandRequests $requests): JsonResponse|Response
    {
        $listing = $requests->listing((int) $request->user()->id);
        $payload = ['requests' => $listing,
            'open' => collect($listing)->contains(fn ($row) => in_array($row['status'], ['pending', 'needs_information'], true)),
            // Presentation only: the account registered through the Brand entrance and has not yet submitted a request.
            'registering' => $request->user()->registration_workspace_kind === 'brand'];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('BrandRequests', $payload);
    }

    public function store(Request $request, BrandRequests $requests): JsonResponse|RedirectResponse
    {
        $data = $request->validate(self::RULES, self::MESSAGES);
        $id = $this->guarded(fn () => $requests->submit((int) $request->user()->id, $data));

        return $this->completed($request, ['id' => $id, 'message' => 'Brand request submitted for review'], '/brand/requests/'.$id, 201);
    }

    public function show(Request $request, BrandRequests $requests, string $brandRequest): JsonResponse|Response
    {
        $payload = ['request' => $this->guarded(fn () => $requests->detail((int) $request->user()->id, $brandRequest))];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('BrandRequest', $payload);
    }

    public function update(Request $request, BrandRequests $requests, string $brandRequest): JsonResponse|RedirectResponse
    {
        $data = $request->validate([...self::RULES, 'expected_version' => ['required', 'integer', 'min:1']], self::MESSAGES);
        $version = $this->guarded(fn () => $requests->revise((int) $request->user()->id, $brandRequest, (int) $data['expected_version'], $data));

        return $this->completed($request, ['version' => $version, 'message' => 'Brand request resubmitted for review'], '/brand/requests/'.$brandRequest);
    }

    /** Maps database refusals to the shared contract: 404 inaccessible, 409 stale or duplicate, 422 invalid. */
    private function guarded(\Closure $action): mixed
    {
        try {
            return $action();
        } catch (QueryException $error) {
            $state = $error->errorInfo[0] ?? '';
            abort_if($state === 'P0002', 404);
            abort_if($state === '42501', 403);
            if ($state === '40001') {
                throw ValidationException::withMessages(['expected_version' => 'This request changed. Reload it before saving again.'])->status(409);
            }
            if ($state === '23505') {
                throw ValidationException::withMessages(['brand_name' => 'You already have a Brand request open. Wait for its review before requesting another.'])->status(409);
            }
            if ($state === '22000') {
                throw ValidationException::withMessages(['brand_name' => 'Enter the Brand name, web address, your role and the intended use.']);
            }
            throw $error;
        }
    }
}
