<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
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
