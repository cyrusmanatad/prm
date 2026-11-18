<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Mockery;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class ExceptionRedactionTest extends TestCase
{
    public function test_database_exception_reporting_excludes_sql_bindings_and_nested_messages(): void
    {
        Log::spy();
        $previous = new PDOException('sensitive-provider-error with token-secret');
        $previous->errorInfo = ['23505', 7, 'sensitive-driver-detail'];
        $exception = new QueryException('pgsql', 'INSERT INTO private_table VALUES (?)', ['binding-secret'], $previous);
        app(ExceptionHandler::class)->report($exception);
        Log::shouldHaveReceived('error')->once()->with('application.exception', Mockery::on(function (array $context): bool {
            $json = json_encode($context);
            foreach (['private_table', 'binding-secret', 'token-secret', 'sensitive-driver-detail', 'args', 'previous'] as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }
            $this->assertSame(QueryException::class, $context['exception_class']);
            $this->assertSame('23505', $context['sqlstate']);
            $this->assertNotEmpty($context['frames']);
            $this->assertArrayNotHasKey('exception', $context);

            return true;
        }));
    }

    public function test_other_exception_messages_and_custom_context_are_not_logged(): void
    {
        Log::spy();
        $exception = new class('https://example.test/onboarding/token-secret') extends RuntimeException
        {
            public function context(): array
            {
                return ['recipient' => 'private@example.test'];
            }
        };
        app(ExceptionHandler::class)->report($exception);
        Log::shouldHaveReceived('error')->once()->with('application.exception', Mockery::on(function (array $context): bool {
            $this->assertStringNotContainsString('token-secret', json_encode($context));
            $this->assertStringNotContainsString('private@example.test', json_encode($context));
            $this->assertArrayNotHasKey('sqlstate', $context);

            return true;
        }));
    }

    public function test_expected_quota_errors_still_do_not_emit_exception_logs(): void
    {
        Log::spy();
        $exception = new PDOException('sensitive quota detail');
        $exception->errorInfo = ['P0004', 7, 'sensitive quota detail'];
        app(ExceptionHandler::class)->report($exception);
        Log::shouldNotHaveReceived('error');
    }
}
