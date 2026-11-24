<?php

namespace App\Http\Controllers;

use App\Services\LeadRegistrations;
use App\Services\PortalHome;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Home for the selected workspace: a separate destination beside the existing Partner hub, My leads and Professional
 * Hub, which keep their routes and behavior. The URL must name the selected workspace's experience.
 */
class HomeController extends Controller
{
    public function index(Request $request, PortalHome $home, LeadRegistrations $leads): mixed
    {
        $org = $request->session()->get('active_org_id');
        $workspace = DB::table('organizations')->where('id', $org)->first(['id', 'name', 'kind', 'experience']);
        abort_unless($workspace, 404);
        $experience = $workspace->kind === 'brand' ? 'brand' : ($workspace->experience === 'professional' ? 'professional' : 'partner');
        $requested = $request->segment(1);
        if ($requested !== $experience) {
            // Professional workspaces open Partner addresses at their Professional equivalents, as the other tools do.
            abort_unless($requested === 'partner' && $experience === 'professional', 404);

            return redirect('/professional/home');
        }
        $payload = ['experience' => $experience, 'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'home' => $experience === 'brand' ? $home->brand($org, $leads->canManage($org)) : $home->creator($org, $experience === 'professional')];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('Home', $payload);
    }
}
