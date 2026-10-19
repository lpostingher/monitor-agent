<?php

namespace Postingher\MonitorAgent;

use Illuminate\Support\Str;

/**
 * Identifies the unit of work (request, job or command) events belong to,
 * so the panel can show the logs written alongside an exception.
 */
class Trace
{
    private ?string $id = null;

    /**
     * Get the current trace id, starting one when needed.
     */
    public function id(): string
    {
        return $this->id ??= (string) Str::uuid();
    }

    /**
     * Start a new trace for the next unit of work.
     */
    public function start(): string
    {
        return $this->id = (string) Str::uuid();
    }
}
