<?php

use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Tests\Fixtures\ProcessOrder;
use Postingher\MonitorAgent\Tests\Fixtures\SyncStock;

beforeEach(function () {
    fakePanel();
    config(['queue.default' => 'database', 'queue.failed.database' => 'testing']);
    Artisan::call('migrate', ['--path' => Orchestra\Testbench\default_migration_path(), '--realpath' => true, '--force' => true]);
});

function work(): void
{
    test()->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0])->assertSuccessful();
}

test('reports processed jobs on the trace of the logs they write', function () {
    ProcessOrder::dispatch()->onQueue('default');

    work();

    $events = sentEvents();
    $job = collect($events)->firstWhere('type', 'job');
    $log = collect($events)->firstWhere('message', 'Processing order');
    expect($job)->toMatchArray([
        'name' => ProcessOrder::class,
        'connection' => 'database',
        'queue' => 'default',
        'attempt' => 1,
        'status' => 'processed',
    ])->and($job['duration_ms'])->toBeGreaterThanOrEqual(0)
        ->and($job['job_id'])->toBeString()
        ->and($job['started_at'])->toEndWith('Z')
        ->and($job['finished_at'])->toEndWith('Z')
        ->and($log['trace_id'])->toBe($job['trace_id']);
});

test('reports failed jobs and links their exception through the trace', function () {
    SyncStock::dispatch();

    work();

    $events = collect(sentEvents());
    $jobs = $events->where('type', 'job')->values();
    $exception = $events->firstWhere('type', 'exception');
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]['status'])->toBe('failed')
        ->and($exception['message'])->toBe('Stock API timed out')
        ->and($exception['trace_id'])->toBe($jobs[0]['trace_id'])
        ->and($exception['context']['job'])->toBe(['name' => SyncStock::class, 'queue' => 'default', 'attempt' => 1]);
});

test('each job runs on its own trace', function () {
    ProcessOrder::dispatch();
    ProcessOrder::dispatch();

    work();

    $traceIds = collect(sentEvents())->where('type', 'job')->pluck('trace_id')->unique();
    expect($traceIds)->toHaveCount(2);
});

test('sync jobs keep the trace of the request that runs them', function () {
    config(['queue.default' => 'sync']);
    Route::get('/order', function () {
        ProcessOrder::dispatch();
        Log::info('After the job');

        return 'ok';
    });

    $this->get('/order')->assertOk();

    $events = collect(sentEvents());
    expect($events->pluck('trace_id')->unique())->toHaveCount(1)
        ->and($events->firstWhere('type', 'job')['connection'])->toBe('sync');
});

test('an idle worker sends events once the flush interval passes', function () {
    app(Agent::class)->record(['type' => 'log', 'message' => 'waiting']);
    Http::assertNothingSent();

    $this->travel(11)->seconds();
    event(new Looping('database', 'default'));

    Http::assertSentCount(1);
});

test('a stopping worker sends what is left', function () {
    app(Agent::class)->record(['type' => 'log', 'message' => 'last words']);

    event(new WorkerStopping(0));

    Http::assertSentCount(1);
});
