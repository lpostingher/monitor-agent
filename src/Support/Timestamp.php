<?php

namespace Postingher\MonitorAgent\Support;

use Illuminate\Support\Facades\Date;

class Timestamp
{
    /**
     * Get the current time in UTC, in the format the panel expects.
     */
    public static function now(): string
    {
        return Date::now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * Format a Unix timestamp with microseconds, such as REQUEST_TIME_FLOAT.
     */
    public static function fromUnix(float $seconds): string
    {
        return Date::createFromTimestamp($seconds, 'UTC')->format('Y-m-d\TH:i:s.u\Z');
    }
}
