<?php

namespace Postingher\MonitorAgent\Collectors;

use Illuminate\Console\Application;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Support\Timestamp;
use Postingher\MonitorAgent\Trace;
use Throwable;

/**
 * Reports every run of a scheduled task. Each run gets its own trace, kept until
 * the next task starts, so the exception the scheduler reports after a failure
 * still belongs to the task.
 */
class ScheduledTaskCollector
{
    /**
     * Tasks in progress, keyed by task object id.
     *
     * @var array<int, true>
     */
    private array $running = [];

    public function __construct(
        private Agent $agent,
        private Trace $trace,
        private ExceptionCollector $exceptions,
    ) {}

    /**
     * Get a readable name for a task: its description, its command, or "Closure".
     */
    public static function nameOf(Event $task): string
    {
        if (is_string($task->description) && $task->description !== '') {
            return $task->description;
        }

        if ($task->command === null) {
            return 'Closure';
        }

        return trim(str_replace([Application::phpBinary(), Application::artisanBinary()], '', $task->command));
    }

    /**
     * Start a new trace for the task.
     */
    public function starting(ScheduledTaskStarting $event): void
    {
        try {
            $this->trace->start();
            $this->exceptions->withContext(['scheduled_task' => self::nameOf($event->task)]);
            $this->running[spl_object_id($event->task)] = true;
        } catch (Throwable) {
            // Never let monitoring break the application.
        }
    }

    /**
     * Report a run that finished, failed when its command exited with an error.
     */
    public function finished(ScheduledTaskFinished $event): void
    {
        $this->finish($event->task, ($event->task->exitCode ?? 0) === 0 ? 'processed' : 'failed', $event->runtime);
    }

    /**
     * Report a run that threw.
     */
    public function failed(ScheduledTaskFailed $event): void
    {
        $this->finish($event->task, 'failed', $event->runtime);
    }

    /**
     * Report the end of a run once, whichever event announces it first.
     */
    private function finish(Event $task, string $status, ?float $runtime): void
    {
        try {
            if (! isset($this->running[spl_object_id($task)])) {
                return;
            }

            unset($this->running[spl_object_id($task)]);

            $this->agent->record([
                'type' => 'scheduled_task',
                'trace_id' => $this->trace->id(),
                'name' => mb_strcut(self::nameOf($task), 0, 255),
                'expression' => $task->expression,
                'status' => $status,
                'duration_ms' => $runtime === null ? null : round($runtime * 1000, 2),
                'finished_at' => Timestamp::now(),
            ]);
        } catch (Throwable) {
            // Never let monitoring break the application.
        }
    }
}
