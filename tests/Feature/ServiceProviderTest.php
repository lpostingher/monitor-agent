<?php

use Postingher\MonitorAgent\MonitorAgentServiceProvider;

function provider(): MonitorAgentServiceProvider
{
    return app()->getProvider(MonitorAgentServiceProvider::class);
}

test('merges the default configuration', function () {
    expect(config('monitor-agent.log_level'))->toBe('info')
        ->and(config('monitor-agent.batch_size'))->toBe(100)
        ->and(config('monitor-agent.flush_interval'))->toBe(10)
        ->and(config('monitor-agent.max_buffer'))->toBe(1000)
        ->and(config('monitor-agent.redact_keys'))->toContain('password');
});

test('is enabled with a url and token in production', function () {
    expect(provider()->isEnabled())->toBeTrue();
});

test('is disabled without a token', function () {
    config(['monitor-agent.token' => null]);

    expect(provider()->isEnabled())->toBeFalse();
});

test('is disabled when switched off', function () {
    config(['monitor-agent.enabled' => false]);

    expect(provider()->isEnabled())->toBeFalse();
});

test('is disabled while running tests', function () {
    app()['env'] = 'testing';

    expect(provider()->isEnabled())->toBeFalse();
});

test('keeps its settings apart from an application config named monitor', function () {
    config(['monitor' => ['retention_days' => 30]]);

    expect(config('monitor-agent.log_level'))->toBe('info')
        ->and(config('monitor'))->toBe(['retention_days' => 30]);
});
