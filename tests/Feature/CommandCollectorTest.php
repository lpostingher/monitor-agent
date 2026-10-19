<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Postingher\MonitorAgent\Agent;

beforeEach(fn () => fakePanel());

test('reports artisan commands with their exit code and duration', function () {
    Artisan::command('orders:sync {--force}', function () {
        Log::info('Syncing');

        return 3;
    });

    Artisan::call('orders:sync', ['--force' => true]);

    $events = collect(sentEvents());
    $command = $events->firstWhere('type', 'command');
    expect($command)->toMatchArray(['name' => 'orders:sync', 'exit_code' => 3])
        ->and($command['duration_ms'])->toBeGreaterThanOrEqual(0)
        ->and($command['started_at'])->toEndWith('Z')
        ->and($command['finished_at'])->toEndWith('Z')
        ->and($events->firstWhere('type', 'log')['trace_id'])->toBe($command['trace_id']);
});

test('exceptions inside a command carry its name', function () {
    Artisan::command('orders:import', fn () => report(new RuntimeException('Bad CSV')));

    Artisan::call('orders:import');

    expect(sentEventsOfType('exception')[0]['context']['command'])->toBe('orders:import');
});

test('skips long-running infrastructure commands', function () {
    Artisan::command('queue:work', fn () => 0);

    Artisan::call('queue:work');
    app(Agent::class)->flush();

    expect(app(Agent::class)->pending())->toBe(0);
    Http::assertNothingSent();
});
