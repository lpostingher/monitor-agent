<?php

namespace Postingher\MonitorAgent\Support;

use Illuminate\Database\QueryException;
use Throwable;

class ExceptionMessage
{
    /**
     * Get an exception's message without data that must not leave the application.
     *
     * Query exceptions interpolate the bound values (emails, password hashes, tokens)
     * into their message, so they are rebuilt from the driver error and the raw SQL.
     */
    public static function for(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            $driverError = $exception->getPrevious()?->getMessage() ?? 'Database error';

            return "{$driverError} (SQL: {$exception->getSql()})";
        }

        return $exception->getMessage();
    }
}
