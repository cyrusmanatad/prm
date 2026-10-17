<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RevokeSessionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password:web']]);
        DB::transaction(function () use ($request): void {
            DB::table('sessions')->where('user_id', $request->user()->id)->delete();
            $request->user()->forceFill(['remember_token' => Str::random(60)])->save();
        });
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'All sessions revoked. Sign in again.']);
    }
}
