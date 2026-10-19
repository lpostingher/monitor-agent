<?php

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Postingher\MonitorAgent\Collectors\ScheduledTaskCollector;
use Postingher\MonitorAgent\Trace;

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

test('runs skipped because the previous one still holds the lock are not reported', function () {
    $task = app(Schedule::class)->call(fn () => null)->name('slow-import')->withoutOverlapping();

    event(new ScheduledTaskStarting($task));
    $task->skippedBecauseOverlapping = true;
    event(new ScheduledTaskFinished($task, 0.0));

    expect(collect(sentEventsOrNone())->where('type', 'scheduled_task'))->toBeEmpty();
});

test('background tasks are reported when their process finishes, with the real exit code', function () {
    $task = app(Schedule::class)->command('reports:build')->runInBackground();

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.01));
    expect(collect(sentEventsOrNone())->where('type', 'scheduled_task'))->toBeEmpty();

    $task->exitCode = 1;
    event(new ScheduledBackgroundTaskFinished($task));

    expect(sentEventsOfType('scheduled_task')[0])->toMatchArray([
        'name' => 'reports:build',
        'status' => 'failed',
        'duration_ms' => null,
    ]);
});

test('a scheduled task hands its trace to the command process it starts', function () {
    $task = app(Schedule::class)->command('orders:sync');
    event(new ScheduledTaskStarting($task));
    $taskTrace = app(Trace::class)->id();
    expect(Context::getHidden('monitor_trace_id'))->toBe($taskTrace);

    app()->forgetInstance(Trace::class);
    Artisan::command('orders:sync', fn () => Log::info('Syncing'));
    Artisan::call('orders:sync');

    expect(collect(sentEvents())->firstWhere('type', 'command')['trace_id'])->toBe($taskTrace);
});
