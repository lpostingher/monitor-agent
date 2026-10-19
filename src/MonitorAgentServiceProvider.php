<?php

namespace Postingher\MonitorAgent;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Postingher\MonitorAgent\Collectors\ExceptionCollector;
use Postingher\MonitorAgent\Collectors\JobCollector;
use Postingher\MonitorAgent\Collectors\LogCollector;
use Postingher\MonitorAgent\Support\ContextSanitizer;
use Postingher\MonitorAgent\Support\StackTraceFormatter;
use Throwable;

class MonitorAgentServiceProvider extends ServiceProvider
{
    /**
     * Register the agent's configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitor-agent.php', 'monitor-agent');

        $this->app->singleton(Agent::class);
        $this->app->singleton(Trace::class);
        $this->app->singleton(ContextSanitizer::class, fn () => new ContextSanitizer(
            array_map(strtolower(...), (array) config('monitor-agent.redact_keys')),
            (int) config('monitor-agent.max_context_bytes'),
        ));
        $this->app->singleton(StackTraceFormatter::class, fn () => new StackTraceFormatter(
            $this->app->basePath(),
            (int) config('monitor-agent.max_frames'),
        ));
        $this->app->singleton(ExceptionCollector::class);
        $this->app->singleton(JobCollector::class);
    }

    /**
     * Hook the collectors into the application when the agent is enabled.
     */
    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/monitor-agent.php' => config_path('monitor-agent.php')], 'monitor-agent-config');

        if (! $this->isEnabled()) {
            return;
        }

        $this->registerFlushHooks();
        $this->registerCollectors();
    }

    /**
     * Listen to the application events each collector reports.
     */
    private function registerCollectors(): void
    {
        Event::listen(MessageLogged::class, [LogCollector::class, 'handle']);
        Event::listen(JobProcessing::class, [JobCollector::class, 'processing']);
        Event::listen(JobProcessed::class, [JobCollector::class, 'processed']);
        Event::listen(JobFailed::class, [JobCollector::class, 'failed']);
        Event::listen(JobReleasedAfterException::class, [JobCollector::class, 'released']);
        Event::listen(Looping::class, fn () => $this->app->make(Agent::class)->flushIfDue());
        Event::listen(WorkerStopping::class, fn () => $this->app->make(Agent::class)->flush());

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $exception): void {
                    $this->app->make(ExceptionCollector::class)->handle($exception);
                });
            }
        });
    }

    /**
     * Send buffered events after the response is sent, and when the process ends.
     */
    private function registerFlushHooks(): void
    {
        $flush = fn () => $this->app->make(Agent::class)->flush();

        $this->app->terminating($flush);

        register_shutdown_function($flush);
    }

    /**
     * Determine whether events should be collected and sent.
     */
    public function isEnabled(): bool
    {
        return (bool) config('monitor-agent.enabled')
            && filled(config('monitor-agent.url'))
            && filled(config('monitor-agent.token'))
            && ! $this->app->environment('testing');
    }
}
