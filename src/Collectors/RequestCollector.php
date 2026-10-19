<?php

namespace Postingher\MonitorAgent\Collectors;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Support\ContextSanitizer;
use Postingher\MonitorAgent\Support\RequestUrl;
use Postingher\MonitorAgent\Support\Timestamp;
use Postingher\MonitorAgent\Trace;
use Throwable;

class RequestCollector
{
    public function __construct(
        private Agent $agent,
        private Trace $trace,
        private ContextSanitizer $sanitizer,
    ) {}

    /**
     * Record a handled request, except the framework's health check.
     */
    public function handle(RequestHandled $event): void
    {
        try {
            $request = $event->request;
            $route = $request->route();

            if ($route?->uri() === 'up') {
                return;
            }

            $startedAt = (float) (defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT', microtime(true)));

            $this->agent->record([
                'type' => 'request',
                'trace_id' => $this->trace->id(),
                'started_at' => Timestamp::fromUnix($startedAt),
                'duration_ms' => round(max(0, microtime(true) - $startedAt) * 1000, 2),
                'method' => $request->method(),
                'status' => $event->response->getStatusCode(),
                'route_uri' => $route?->uri(),
                'action' => $route?->getActionName(),
                'url' => RequestUrl::for($request, $this->sanitizer),
                'user_id' => app()->bound('auth') && auth()->hasUser() ? (string) auth()->id() : null,
            ]);
        } catch (Throwable) {
            // Never let monitoring break the application.
        }
    }
}
