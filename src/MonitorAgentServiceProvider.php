<?php

namespace Postingher\MonitorAgent;

use Illuminate\Support\ServiceProvider;

class MonitorAgentServiceProvider extends ServiceProvider
{
    /**
     * Register the agent's configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitor.php', 'monitor');

        $this->app->singleton(Agent::class);
        $this->app->singleton(Trace::class);
    }

    /**
     * Hook the collectors into the application when the agent is enabled.
     */
    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/monitor.php' => config_path('monitor.php')], 'monitor-config');

        if (! $this->isEnabled()) {
            return;
        }

        $this->registerFlushHooks();
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
        return (bool) config('monitor.enabled')
            && filled(config('monitor.url'))
            && filled(config('monitor.token'))
            && ! $this->app->environment('testing');
    }
}
