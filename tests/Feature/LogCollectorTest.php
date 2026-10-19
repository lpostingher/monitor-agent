<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Collectors\LogCollector;
use Postingher\MonitorAgent\Trace;

beforeEach(fn () => Http::fake(['*' => Http::response(['accepted' => 1], 202)]));

test('reports logs with their level, message, context and trace', function () {
    Log::error('Payment failed', ['order' => 1042]);

    [$event] = sentEvents();

    expect($event)->toMatchArray([
        'type' => 'log',
        'trace_id' => app(Trace::class)->id(),
        'level' => 'error',
        'message' => 'Payment failed',
        'context' => ['order' => 1042],
    ])->and($event['logged_at'])->toEndWith('Z');
});

test('skips levels below the configured minimum', function () {
    Log::debug('Noise');
    Log::info('Signal');

    expect(array_column(sentEvents(), 'message'))->toBe(['Signal']);
});

test('reports debug logs when the minimum level is debug', function () {
    config(['monitor-agent.log_level' => 'debug']);

    Log::debug('Noise');

    expect(array_column(sentEvents(), 'message'))->toBe(['Noise']);
});

test('redacts sensitive context', function () {
    Log::warning('Login attempt', ['email' => 'ana@example.com', 'password' => 'hunter2']);

    expect(sentEvents()[0]['context'])->toBe(['email' => 'ana@example.com', 'password' => '[redacted]']);
});

test('logs written while a batch is being sent are not collected', function () {
    Http::fake(function () {
        Log::error('Written during flush');

        return Http::response(['accepted' => 1], 202);
    });
    Log::error('Original');

    app(Agent::class)->flush();

    expect(app(Agent::class)->pending())->toBe(0);
});

test('listens to logs only when enabled', function () {
    $collectorListeners = fn () => collect(Event::getRawListeners()[MessageLogged::class] ?? [])
        ->filter(fn ($listener) => $listener === [LogCollector::class, 'handle']);

    expect($collectorListeners())->toHaveCount(1);

    $this->refreshApplicationWithToken(null);

    expect($collectorListeners())->toBeEmpty();
});
