<?php

namespace Postingher\MonitorAgent\Collectors;

use Illuminate\Database\Events\QueryExecuted;
use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Support\StackTraceFormatter;
use Postingher\MonitorAgent\Support\Timestamp;
use Postingher\MonitorAgent\Trace;
use Throwable;

/**
 * Reports executed queries with their placeholders (never the bound values)
 * and the application code that ran them.
 */
class QueryCollector
{
    /**
     * Longest SQL sent, in bytes.
     */
    private const MAX_SQL_BYTES = 10240;

    /**
     * Stack frames inspected to find the application code behind a query.
     */
    private const BACKTRACE_LIMIT = 40;

    /**
     * Front controllers that sit under every framework call, so they never identify the caller.
     */
    private const ENTRY_POINTS = ['artisan', 'public/index.php'];

    public function __construct(
        private Agent $agent,
        private Trace $trace,
        private StackTraceFormatter $formatter,
    ) {}

    /**
     * Record an executed query.
     */
    public function handle(QueryExecuted $event): void
    {
        try {
            if ($this->agent->isFlushing()) {
                return;
            }

            [$file, $line] = $this->location();

            // Framework housekeeping (queue polling, sessions, cache) has no application code behind it.
            if ($file === null) {
                return;
            }

            $this->agent->record([
                'type' => 'query',
                'trace_id' => $this->trace->id(),
                'executed_at' => Timestamp::now(),
                'sql' => mb_strcut($this->collapseInLists($event->sql), 0, self::MAX_SQL_BYTES),
                'duration_ms' => round((float) $event->time, 2),
                'connection' => $event->connectionName,
                'file' => $file,
                'line' => $line,
            ]);
        } catch (Throwable) {
            // Never let monitoring break the application.
        }
    }

    /**
     * Collapse "in (...)" lists of placeholders or literal ids, so eager loads and
     * whereIn queries keep one shape whatever the list size.
     */
    private function collapseInLists(string $sql): string
    {
        return (string) preg_replace('/\bin\s*\((?:\s*(?:\?|\d+)\s*,?)+\)/i', 'in (...)', $sql);
    }

    /**
     * Find the first application frame that is not this package.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function location(): array
    {
        $packagePath = dirname(__DIR__, 2).'/src/';

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::BACKTRACE_LIMIT) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || str_starts_with($file, $packagePath) || ! $this->formatter->isInApp($file)) {
                continue;
            }

            $relativePath = $this->formatter->relativePath($file);

            if (! in_array($relativePath, self::ENTRY_POINTS, true)) {
                return [mb_strcut($relativePath, 0, 255), $frame['line'] ?? null];
            }
        }

        return [null, null];
    }
}
