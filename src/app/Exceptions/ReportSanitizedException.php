<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Log;
use Throwable;

/** Report failure location without messages, SQL, bindings, URLs or exception objects. */
class ReportSanitizedException
{
    public function __invoke(Throwable $exception): bool
    {
        Log::error('application.exception', SanitizedExceptionDetails::from($exception));

        // Stop Laravel's default reporter from attaching the raw exception and its context.
        return false;
    }
}
