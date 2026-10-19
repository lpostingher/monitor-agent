<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Postingher\MonitorAgent\Collectors\ScheduledTaskCollector;

beforeEach(fn () => fakePanel());

test('reports scheduled tasks that ran and those that failed', function () {
    $schedule = app(Schedule::class);
    $schedule->call(fn () => Log::info('Pruning'))->everyMinute()->name('prune-carts');
    $schedule->call(fn () => throw new RuntimeException('Feed down'))->hourly()->name('import-feed');
    $this->travelTo(now()->startOfHour());

    $this->artisan('schedule:run')->run();

    $events = collect(sentEvents());
    $tasks = $events->where('type', 'scheduled_task')->keyBy('name');
    expect($tasks['prune-carts'])->toMatchArray(['expression' => '* * * * *', 'status' => 'processed'])
        ->and($tasks['import-feed'])->toMatchArray(['expression' => '0 * * * *', 'status' => 'failed'])
        ->and($tasks['prune-carts']['finished_at'])->toEndWith('Z')
        ->and($events->firstWhere('message', 'Pruning')['trace_id'])->toBe($tasks['prune-carts']['trace_id'])
        ->and($events->firstWhere('message', 'Feed down')['trace_id'])->toBe($tasks['import-feed']['trace_id'])
        ->and($events->firstWhere('message', 'Feed down')['context']['scheduled_task'])->toBe('import-feed');
});

test('names command tasks without the php and artisan binaries', function () {
    $task = app(Schedule::class)->command('reports:send --daily');

    expect(ScheduledTaskCollector::nameOf($task))->toBe('reports:send --daily');
});
