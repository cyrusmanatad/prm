<?php

namespace App\Queue;

use App\Exceptions\SanitizedExceptionDetails;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Throwable;

/** Keep diagnostic classes/locations without persisting raw SQL, message content or trace arguments. */
class SanitizedFailedJobProvider extends DatabaseUuidFailedJobProvider
{
    public function log($connection, $queue, $payload, $exception)
    {
        $details = $exception instanceof Throwable ? SanitizedExceptionDetails::from($exception) : ['exception_class' => 'Exception'];
        $text = $details['exception_class'].": failure details redacted\n".json_encode($details, JSON_THROW_ON_ERROR);

        return parent::log($connection, $queue, $payload, $text);
    }
}
