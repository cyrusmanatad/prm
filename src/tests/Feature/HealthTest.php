<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_liveness_endpoint_is_available_without_a_workspace(): void
    {
        $this->get('/up')->assertOk();
    }
}
