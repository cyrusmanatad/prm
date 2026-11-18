<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicAssetPathTest extends TestCase
{
    /**
     * The web server tries `$uri/` before the application. A public directory named like a route's first
     * segment makes it answer that route itself with a directory redirect, as `public/brand/` did to `/brand`.
     */
    public function test_no_public_directory_shadows_an_application_route(): void
    {
        $segments = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => explode('/', trim($route->uri(), '/'))[0])
            ->filter(fn ($segment) => $segment !== '' && ! str_contains($segment, '{'))
            ->unique();
        $directories = collect(glob(public_path('*'), GLOB_ONLYDIR))->map(fn ($path) => basename($path));

        $this->assertSame([], $directories->intersect($segments)->values()->all());
        $this->assertContains('brand', $segments->all());
    }
}
