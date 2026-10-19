<?php

namespace Postingher\MonitorAgent;

use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Buffers events in memory and sends them to the panel in batches.
 *
 * HTTP requests send once, after the response, when the application terminates.
 * Console processes such as queue workers also send whenever a batch is full or
 * the flush interval has passed, so events never wait for the process to end.
 */
class Agent
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $events = [];

    private bool $flushing = false;

    private int $lastFlushAt;

    public function __construct(private HttpTransport $transport)
    {
        $this->lastFlushAt = Date::now()->getTimestamp();
    }

    /**
     * Buffer an event, dropping it when the buffer is full or a batch is being sent.
     *
     * @param  array<string, mixed>  $event
     */
    public function record(array $event): void
    {
        if ($this->flushing || count($this->events) >= (int) config('monitor.max_buffer')) {
            return;
        }

        $this->events[] = $event;

        if (app()->runningInConsole()) {
            $this->flushIfDue();
        }
    }

    /**
     * Send the batch when it is full or the flush interval has passed.
     */
    public function flushIfDue(): void
    {
        $isFull = count($this->events) >= (int) config('monitor.batch_size');
        $isStale = Date::now()->getTimestamp() - $this->lastFlushAt >= (int) config('monitor.flush_interval');

        if ($isFull || ($isStale && $this->events !== [])) {
            $this->flush();
        }
    }

    /**
     * Send every buffered event. Failures are ignored and the events dropped.
     */
    public function flush(): void
    {
        if ($this->events === [] || $this->flushing) {
            return;
        }

        $this->flushing = true;
        $events = $this->events;
        $this->events = [];

        try {
            $this->transport->send($events);
        } catch (Throwable) {
            // Never let monitoring break the application.
        } finally {
            $this->flushing = false;
            $this->lastFlushAt = Date::now()->getTimestamp();
        }
    }

    /**
     * Determine whether a batch is being sent right now.
     */
    public function isFlushing(): bool
    {
        return $this->flushing;
    }

    /**
     * Get the number of buffered events.
     */
    public function pending(): int
    {
        return count($this->events);
    }
}
