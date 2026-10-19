<?php

namespace Postingher\MonitorAgent\Collectors;

use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Support\ContextSanitizer;
use Postingher\MonitorAgent\Support\ExceptionMessage;
use Postingher\MonitorAgent\Support\RequestUrl;
use Postingher\MonitorAgent\Support\StackTraceFormatter;
use Postingher\MonitorAgent\Support\Timestamp;
use Postingher\MonitorAgent\Trace;
use Throwable;
use WeakMap;

class ExceptionCollector
{
    /**
     * Longest message stored by the panel, in bytes.
     */
    private const MAX_MESSAGE_BYTES = 65535;

    /**
     * Longest exception class or file path stored by the panel.
     */
    private const MAX_NAME_BYTES = 255;

    /**
     * Extra context describing the current unit of work, such as the job being processed.
     *
     * @var array<string, mixed>
     */
    private array $context = [];

    /**
     * Exceptions already recorded, so one reported twice counts once.
     *
     * Handlers decorated by other packages (such as Collision in the console)
     * can run the reportable callback more than once for a single report.
     *
     * @var WeakMap<Throwable, true>
     */
    private WeakMap $recorded;

    public function __construct(
        private Agent $agent,
        private Trace $trace,
        private ContextSanitizer $sanitizer,
        private StackTraceFormatter $formatter,
    ) {
        $this->recorded = new WeakMap;
    }

    /**
     * Record an exception the application reported.
     */
    public function handle(Throwable $exception): void
    {
        try {
            if ($this->agent->isFlushing() || isset($this->recorded[$exception])) {
                return;
            }

            $this->recorded[$exception] = true;

            $this->agent->record([
                'type' => 'exception',
                'trace_id' => $this->trace->id(),
                'occurred_at' => Timestamp::now(),
                'class' => mb_strcut($exception::class, 0, self::MAX_NAME_BYTES),
                'message' => mb_strcut(ExceptionMessage::for($exception), 0, self::MAX_MESSAGE_BYTES),
                'code' => (string) $exception->getCode(),
                'file' => mb_strcut($this->formatter->relativePath($exception->getFile()), 0, self::MAX_NAME_BYTES),
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
                'url' => RequestUrl::for($request, $this->sanitizer),
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
