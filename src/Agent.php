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
     * Event types that may only fill two thirds of the buffer (by count and by size),
     * so a burst of them never pushes out the exceptions, jobs and requests recorded afterwards.
     */
    private const HIGH_VOLUME_TYPES = ['log', 'query', 'outgoing_request', 'cache'];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $events = [];

    private bool $flushing = false;

    private int $bytes = 0;

    private bool $holdingFlushes = false;

    /**
     * Callbacks that add their summarized events right before a batch is sent.
     *
     * @var array<int, callable(): void>
     */
    private array $beforeFlush = [];

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
        $isHighVolume = in_array($event['type'] ?? null, self::HIGH_VOLUME_TYPES, true);
        $maxEvents = (int) config('monitor-agent.max_buffer');
        $maxBytes = (int) config('monitor-agent.max_buffer_bytes');

        if ($isHighVolume) {
            $maxEvents = intdiv($maxEvents * 2, 3);
            $maxBytes = intdiv($maxBytes * 2, 3);
        }

        if ($this->flushing || count($this->events) >= $maxEvents) {
            return;
        }

        $size = strlen((string) json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        if ($this->bytes + $size > $maxBytes) {
            return;
        }

        $this->events[] = $event;
        $this->bytes += $size;

        // High-volume events often come from inside a transaction or a tight loop, so
        // they never start a send themselves; other events, the worker loop and the
        // end of the process send them.
        if (app()->runningInConsole() && ! $this->holdingFlushes && ! $isHighVolume) {
            $this->flushIfDue();
        }
    }

    /**
     * Keep events buffered while a queued job runs, so it never waits on the panel
     * (possibly while holding database locks). The worker sends them between jobs.
     */
    public function holdFlushes(bool $hold): void
    {
        $this->holdingFlushes = $hold;
    }

    /**
     * Send the batch when it is full or the flush interval has passed.
     */
    public function flushIfDue(): void
    {
        $isFull = count($this->events) >= (int) config('monitor-agent.batch_size');
        $isStale = Date::now()->getTimestamp() - $this->lastFlushAt >= (int) config('monitor-agent.flush_interval');

        if ($isFull || ($isStale && $this->events !== [])) {
            $this->flush();
        }
    }

    /**
     * Run a callback right before each batch is sent, for collectors that summarize in memory.
     *
     * @param  callable(): void  $callback
     */
    public function beforeFlush(callable $callback): void
    {
        $this->beforeFlush[] = $callback;
    }

    /**
     * Send every buffered event. Failures are ignored and the events dropped.
     */
    public function flush(): void
    {
        if ($this->flushing) {
            return;
        }

        foreach ($this->beforeFlush as $callback) {
            try {
                $callback();
            } catch (Throwable) {
                // Never let monitoring break the application.
            }
        }

        if ($this->events === []) {
            return;
        }

        $this->flushing = true;
        $events = $this->events;
        $this->events = [];
        $this->bytes = 0;

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
