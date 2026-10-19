<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Collectors\QueryCollector;
use Postingher\MonitorAgent\Support\StackTraceFormatter;

beforeEach(function () {
    fakePanel();
    app()->instance(StackTraceFormatter::class, new StackTraceFormatter(dirname(__DIR__, 2), 50));
});

test('reports queries without their bound values, with the code that ran them', function () {
    Log::info('Before');
    DB::select('select ? as email', ['ana@example.com']);

    $events = collect(sentEvents());
    $query = $events->firstWhere('type', 'query');
    expect($query)->toMatchArray([
        'sql' => 'select ? as email',
        'connection' => 'testing',
        'file' => 'tests/Feature/QueryCollectorTest.php',
        'trace_id' => $events->firstWhere('type', 'log')['trace_id'],
    ])->and($query['line'])->toBeInt()
        ->and($query['duration_ms'])->toBeGreaterThanOrEqual(0)
        ->and($query['executed_at'])->toEndWith('Z')
        ->and(json_encode($events->all()))->not->toContain('ana@example.com');
});

test('a burst of queries never pushes an exception out of the batch', function () {
    config(['monitor-agent.max_buffer_bytes' => 30_000, 'monitor-agent.batch_size' => 10_000]);
    app()['env'] = 'production';
    Route::get('/report', function () {
        foreach (range(1, 300) as $index) {
            DB::select('select '.$index.' as row_number_with_a_long_alias_to_take_space');
        }
        report(new RuntimeException('After the burst'));

        return 'ok';
    });

    $this->get('/report');

    expect(collect(sentEvents())->firstWhere('type', 'exception')['message'])->toBe('After the burst');
});

test('more queries than the event limit never push the request or its exception out', function () {
    app()['env'] = 'production';
    Route::get('/import', function () {
        foreach (range(1, 1100) as $index) {
            DB::select('select '.$index);
        }
        report(new RuntimeException('Import failed'));

        return 'ok';
    });

    $this->get('/import');

    $events = collect(sentEvents());
    expect($events->firstWhere('type', 'exception')['message'])->toBe('Import failed')
        ->and($events->firstWhere('type', 'request'))->not->toBeNull();
});

test('queries never start a send in the middle of a command', function () {
    config(['monitor-agent.batch_size' => 2]);

    foreach (range(1, 5) as $index) {
        DB::select('select '.$index);
    }

    Http::assertNothingSent();
    expect(app(Agent::class)->pending())->toBe(5);
});

test('in lists are collapsed so one query keeps one shape', function () {
    DB::select('select 1 where 1 in (1, 2, 3) and 2 in (?, ?)', [2, 3]);

    expect(collect(sentEvents())->firstWhere('type', 'query')['sql'])
        ->toBe('select 1 where 1 in (...) and 2 in (...)');
});

test('queries with no application code behind them are skipped', function () {
    app()->instance(StackTraceFormatter::class, new StackTraceFormatter('/nonexistent', 50));
    app()->forgetInstance(QueryCollector::class);

    DB::select('select 1');
    app(Agent::class)->flush();

    expect(app(Agent::class)->pending())->toBe(0);
    Http::assertNothingSent();
});

test('entry points like public/index.php and artisan do not count as application code', function () {
    app()->instance(StackTraceFormatter::class, new StackTraceFormatter(dirname(__DIR__).'/Fixtures/app', 50));
    app()->forgetInstance(QueryCollector::class);

    require dirname(__DIR__).'/Fixtures/app/public/index.php';
    app(Agent::class)->flush();

    Http::assertNothingSent();
});
