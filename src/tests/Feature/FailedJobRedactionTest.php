<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Tests\TestCase;

class FailedJobRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_job_storage_retains_recovery_payload_but_redacts_exception_details(): void
    {
        $id = (string) Str::uuid();
        $payload = json_encode(['uuid' => $id, 'displayName' => 'SyntheticJob', 'data' => ['id' => 'synthetic-routing-id']]);
        $previous = new PDOException('provider-secret-message');
        $previous->errorInfo = ['23505', 7, 'private driver detail'];
        $error = new QueryException('pgsql', 'INSERT INTO private_table VALUES (?)', ['private-binding-token'], $previous);
        $this->assertSame($id, app('queue.failer')->log('redis', 'synthetic', $payload, $error));
        $row = DB::table('failed_jobs')->where('uuid', $id)->first();
        $this->assertSame($payload, $row->payload);
        $this->assertStringStartsWith(QueryException::class.':', $row->exception);
        foreach (['provider-secret-message', 'private driver detail', 'private_table', 'private-binding-token', 'args'] as $secret) {
            $this->assertStringNotContainsString($secret, $row->exception);
        }
        $this->assertStringContainsString('23505', $row->exception);
        $this->assertSame($payload, app('queue.failer')->find($id)->payload);
    }
}
