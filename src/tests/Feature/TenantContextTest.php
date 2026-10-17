<?php

namespace Tests\Feature;

use App\Tenancy\TenantContext;
use LogicException;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    public function test_tenant_operations_refuse_sqlite_instead_of_simulating_rls(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Tenant operations require PostgreSQL.');
        app(TenantContext::class)->run(1, null, fn () => $this->fail('Tenant callback must not run'));
    }
}
