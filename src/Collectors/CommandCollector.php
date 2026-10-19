<?php

namespace Postingher\MonitorAgent\Collectors;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Support\Timestamp;
use Postingher\MonitorAgent\Trace;
use Throwable;

/**
 * Reports Artisan commands by name (never their arguments) with exit code and duration.
 */
class CommandCollector
{
    /**
     * Long-running or infrastructure commands that would only add noise.
     */
    private const IGNORED = [
        'queue:work', 'queue:listen', 'schedule:run', 'schedule:work', 'schedule:finish',
        'horizon', 'horizon:work', 'horizon:supervisor', 'octane:start', 'serve', 'tinker', 'pail',
    ];

    /**
     * Commands in progress, innermost last; null marks one that is not reported.
     *
     * @var array<int, array{name: string, started_at: string, hrtime: int}|null>
     */
    private array $running = [];

    public function __construct(
        private Agent $agent,
        private Trace $trace,
        private ExceptionCollector $exceptions,
    ) {}

    /**
     * Start timing a command; a top-level console command gets its own trace.
     */
    public function starting(CommandStarting $event): void
    {
        try {
            if ($event->command === null || in_array($event->command, self::IGNORED, true)) {
                $this->running[] = null;

                return;
            }

            if ($this->running === [] && app()->runningInConsole()) {
                $this->trace->start();
                $this->exceptions->withContext(['command' => $event->command]);
            }

            $this->running[] = ['name' => $event->command, 'started_at' => Timestamp::now(), 'hrtime' => hrtime(true)];
        } catch (Throwable) {
            // Never let monitoring break the application.
        }
    }

    /**
     * Report a finished command.
     */
    public function finished(CommandFinished $event): void
    {
        try {
            $command = array_pop($this->running);

            if ($command === null) {
                return;
            }

            $this->agent->record([
                'type' => 'command',
                'trace_id' => $this->trace->id(),
                'name' => $command['name'],
                'exit_code' => $event->exitCode,
                'duration_ms' => round((hrtime(true) - $command['hrtime']) / 1e6, 2),
                'started_at' => $command['started_at'],
                'finished_at' => Timestamp::now(),
            ]);
        } catch (Throwable) {
            // Never let monitoring break the application.
        }
    }
}
