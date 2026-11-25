<?php

namespace App\Http\Controllers\Account;

use App\Enums\Appearance;
use App\Http\Controllers\Controller;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The signed-in account's own accent color. The target is always the session's account, never a submitted id. */
class AppearanceController extends Controller
{
    public function show(Request $request): JsonResponse|Response
    {
        $data = ['appearance' => Appearance::normalize($request->user()->appearance)->value];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($data) : Inertia::render('AccountAppearance', $data);
    }

    public function update(Request $request, TenantContext $context): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['appearance' => ['required', 'string', Rule::enum(Appearance::class)]]);
        $id = (int) $request->user()->id;
        $write = fn () => DB::table('users')->where('id', $id)->update(['appearance' => $data['appearance'], 'updated_at' => now()]);
        // On PostgreSQL the write takes the account's privacy access barrier, like other account changes, so it cannot
        // race a closure or suspension.
        DB::getDriverName() === 'pgsql' ? $context->run($id, null, $write) : $write();
        $request->user()->setAttribute('appearance', $data['appearance'])->syncOriginalAttribute('appearance');

        return $this->completed($request, ['appearance' => $data['appearance'], 'message' => 'Appearance saved'], '/account/appearance');
    }
}
