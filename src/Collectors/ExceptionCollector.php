<?php

namespace Postingher\MonitorAgent\Collectors;

use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Support\ContextSanitizer;
use Postingher\MonitorAgent\Support\StackTraceFormatter;
use Postingher\MonitorAgent\Support\Timestamp;
use Postingher\MonitorAgent\Trace;
use Throwable;

class ExceptionCollector
{
    /**
     * Longest message stored by the panel, in bytes.
     */
    private const MAX_MESSAGE_BYTES = 65535;

    /**
     * Extra context describing the current unit of work, such as the job being processed.
     *
     * @var array<string, mixed>
     */
    private array $context = [];

    public function __construct(
        private Agent $agent,
        private Trace $trace,
        private ContextSanitizer $sanitizer,
        private StackTraceFormatter $formatter,
    ) {}

    /**
     * Record an exception the application reported.
     */
    public function handle(Throwable $exception): void
    {
        try {
            if ($this->agent->isFlushing()) {
                return;
            }

            $this->agent->record([
                'type' => 'exception',
                'trace_id' => $this->trace->id(),
                'occurred_at' => Timestamp::now(),
                'class' => $exception::class,
                'message' => mb_strcut($exception->getMessage(), 0, self::MAX_MESSAGE_BYTES),
                'code' => (string) $exception->getCode(),
                'file' => $this->formatter->relativePath($exception->getFile()),
                'line' => $exception->getLine(),
                'trace' => $this->formatter->format($exception),
                'context' => $this->sanitizer->sanitize($this->context()),
            ]);
        } catch (Throwable) {
            // Never let monitoring break the application.
        }
    }

    /**
     * Describe the current unit of work on the exceptions reported until it is forgotten.
     *
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context): void
    {
        $this->context = $context;
    }

    /**
     * Forget the current unit of work's context.
     */
    public function forgetContext(): void
    {
        $this->context = [];
    }

    /**
     * Describe where the exception happened: the request, job or command, and the user.
     *
     * @return array<string, mixed>
     */
    private function context(): array
    {
        $request = app()->bound('request') ? app('request') : null;
        $route = $request?->route();
        $context = $this->context;

        if ($route !== null) {
            $context['request'] = [
                'method' => $request->method(),
                'url' => $request->url(),
                'route' => $route->getName() ?? $route->uri(),
            ];
        } elseif ($context === [] && app()->runningInConsole()) {
            $context['command'] = mb_strcut(implode(' ', $_SERVER['argv'] ?? []), 0, 255);
        }

        if (app()->bound('auth') && auth()->hasUser()) {
            $context['user'] = ['id' => auth()->id()];
        }

        return $context;
    }
}
