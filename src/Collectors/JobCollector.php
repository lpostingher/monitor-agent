<?php

namespace Postingher\MonitorAgent\Collectors;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Support\Timestamp;
use Postingher\MonitorAgent\Trace;
use Throwable;

/**
 * Reports every attempt of a queued job.
 *
 * Each job processed by a worker gets its own trace, started when it begins so
 * the exception the worker reports after a failure still shares the job's trace.
 * Jobs on the sync connection run inside the current request and keep its trace.
 */
class JobCollector
{
    /**
     * Start time of each attempt in progress, keyed by job object id.
     *
     * @var array<int, array{started_at: string, hrtime: int}>
     */
    private array $attempts = [];

    public function __construct(
        private Agent $agent,
        private Trace $trace,
        private ExceptionCollector $exceptions,
    ) {}

    /**
     * Start tracking an attempt.
     */
    public function processing(JobProcessing $event): void
    {
        try {
            if ($event->connectionName !== 'sync') {
                $this->trace->start();
                $this->exceptions->withContext(['job' => [
                    'name' => $event->job->resolveName(),
                    'queue' => $event->job->getQueue(),
                    'attempt' => $event->job->attempts(),
                ]]);
            }

            $this->attempts[spl_object_id($event->job)] = [
                'started_at' => Timestamp::now(),
                'hrtime' => hrtime(true),
            ];
        } catch (Throwable) {
            // Never let monitoring break the application.
        }
    }

    /**
     * Report an attempt that completed.
     */
    public function processed(JobProcessed $event): void
    {
        $this->finish($event->connectionName, $event->job, 'processed');
    }

    /**
     * Report an attempt that failed for good.
     */
    public function failed(JobFailed $event): void
    {
        $this->finish($event->connectionName, $event->job, 'failed');
    }

    /**
     * Report an attempt that failed and will be retried.
     */
    public function released(JobReleasedAfterException $event): void
    {
        $this->finish($event->connectionName, $event->job, 'released');
    }

    /**
     * Report the end of an attempt once, whichever event announces it first.
     */
    private function finish(string $connectionName, Job $job, string $status): void
    {
        try {
            $attempt = $this->attempts[spl_object_id($job)] ?? null;

            if ($attempt === null) {
                return;
            }

            unset($this->attempts[spl_object_id($job)]);

            $this->agent->record([
                'type' => 'job',
                'trace_id' => $this->trace->id(),
                'job_id' => (string) $job->getJobId(),
                'name' => $job->resolveName(),
                'connection' => $connectionName,
                'queue' => $job->getQueue(),
                'attempt' => $job->attempts(),
                'status' => $status,
                'duration_ms' => round((hrtime(true) - $attempt['hrtime']) / 1e6, 2),
                'started_at' => $attempt['started_at'],
                'finished_at' => Timestamp::now(),
            ]);
        } catch (Throwable) {
            // Never let monitoring break the application.
        }
    }
}
