<?php

namespace Postingher\MonitorAgent\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Postingher\MonitorAgent\MonitorAgentServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * The ingest token the application boots with.
     */
    protected ?string $monitorToken = 'mon_test';

    /**
     * Boot a fresh application with the given ingest token.
     */
    protected function refreshApplicationWithToken(?string $token): void
    {
        $this->monitorToken = $token;

        $this->refreshApplication();
    }

    /**
     * Get the package providers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MonitorAgentServiceProvider::class];
    }

    /**
     * Run the agent as it would in a production app.
     */
    protected function defineEnvironment($app): void
    {
        $app['env'] = 'production';
        $app['config']->set('monitor-agent.enabled', true);
        $app['config']->set('monitor-agent.url', 'https://monitor.test');
        $app['config']->set('monitor-agent.token', $this->monitorToken);
    }
}
