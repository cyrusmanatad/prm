<?php

namespace Tests\Feature;

use App\Services\Onboarding;
use Tests\TestCase;

class PublicCapabilityThrottleTest extends TestCase
{
    public function test_unknown_capabilities_are_generic_and_exchange_attempts_are_bounded(): void
    {
        $this->mock(Onboarding::class, fn ($m) => $m->shouldReceive('exchange')->times(6)->andReturnUsing(fn () => abort(404)));
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/onboarding/session', ['token' => str_repeat('0', 64)])->assertNotFound();
        }
        $this->postJson('/onboarding/session', ['token' => str_repeat('0', 64)])->assertTooManyRequests();
    }
}
