<?php

namespace Postingher\MonitorAgent\Collectors;

use Illuminate\Log\Events\MessageLogged;
use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Support\ContextSanitizer;
use Postingher\MonitorAgent\Support\Timestamp;
use Postingher\MonitorAgent\Trace;
use Stringable;
use Throwable;

class LogCollector
{
    /**
     * PSR-3 levels ordered from least to most severe.
     */
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * Longest message stored by the panel, in bytes.
     */
    private const MAX_MESSAGE_BYTES = 65535;

    public function __construct(
        private Agent $agent,
        private Trace $trace,
        private ContextSanitizer $sanitizer,
    ) {}

    /**
     * Record a log message at or above the configured level.
     */
    public function handle(MessageLogged $event): void
    {
        try {
            $level = strtolower((string) $event->level);

            if ($this->agent->isFlushing() || ! $this->isReported($level)) {
                return;
            }

            $this->agent->record([
                'type' => 'log',
                'trace_id' => $this->trace->id(),
                'logged_at' => Timestamp::now(),
                'level' => $level,
                'message' => mb_strcut($this->messageText($event->message), 0, self::MAX_MESSAGE_BYTES),
                'context' => $this->sanitizer->sanitize($event->context),
            ]);
        } catch (Throwable) {
            // Never let monitoring break the application.
        }
    }

    private function isReported(string $level): bool
    {
        $severity = array_search($level, self::LEVELS, true);
        $minimum = array_search(strtolower((string) config('monitor-agent.log_level')), self::LEVELS, true);

        return $severity !== false && $severity >= ($minimum === false ? 1 : $minimum);
    }

    private function messageText(mixed $message): string
    {
        return is_scalar($message) || $message instanceof Stringable
            ? (string) $message
            : (string) json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
