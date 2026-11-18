<?php

namespace App\Exceptions;

use PDOException;
use Throwable;

class SanitizedExceptionDetails
{
    public static function from(Throwable $exception): array
    {
        $frames = [['file' => $exception->getFile(), 'line' => $exception->getLine()]];
        foreach (array_slice($exception->getTrace(), 0, 20) as $frame) {
            $frames[] = array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function']));
        }
        $context = ['exception_class' => $exception::class, 'frames' => $frames];
        if ($exception instanceof PDOException) {
            $state = $exception->errorInfo[0] ?? null;
            if (is_string($state) && preg_match('/^[A-Z0-9]{5}$/D', $state)) {
                $context['sqlstate'] = $state;
            }
        }

        return $context;
    }
}
