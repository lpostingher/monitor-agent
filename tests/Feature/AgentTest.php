<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Support\Timestamp;

/**
 * Decode the gzip JSON envelope of a request sent to the panel.
 *
 * @return array<string, mixed>
 */
function envelopeOf(Request $request): array
{
    return json_decode(gzdecode($request->body()), true);
}

function agent(): Agent
{
    return app(Agent::class);
}

beforeEach(fn () => Http::fake(['*' => Http::response(['accepted' => 1], 202)]));

test('flush sends one gzip batch with the v1 envelope', function () {
    agent()->record(['type' => 'log', 'message' => 'first']);
    agent()->record(['type' => 'log', 'message' => 'second']);

    agent()->flush();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $envelope = envelopeOf($request);

        return $request->url() === 'https://monitor.test/api/ingest'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer mon_test')
            && $request->hasHeader('Content-Encoding', 'gzip')
            && $envelope['version'] === 1
            && $envelope['environment'] === 'production'
            && $envelope['server'] === gethostname()
            && array_column($envelope['events'], 'message') === ['first', 'second'];
    });
    expect(agent()->pending())->toBe(0);
});

test('flush with nothing buffered sends nothing', function () {
    agent()->flush();

    Http::assertNothingSent();
});

test('terminating the application flushes the buffer', function () {
    agent()->record(['type' => 'log', 'message' => 'bye']);

    app()->terminate();

    Http::assertSentCount(1);
});

test('an unreachable panel never throws and drops the batch', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));
    agent()->record(['type' => 'log', 'message' => 'lost']);

    agent()->flush();

    expect(agent()->pending())->toBe(0);
});

test('a failing panel response never throws', function () {
    Http::fake(['*' => Http::response('down', 500)]);
    agent()->record(['type' => 'log', 'message' => 'lost']);

    agent()->flush();

    expect(agent()->pending())->toBe(0);
});

test('drops events beyond the buffer limit', function () {
    config(['monitor.max_buffer' => 3, 'monitor.batch_size' => 100]);

    foreach (range(1, 5) as $index) {
        agent()->record(['type' => 'log', 'message' => "event {$index}"]);
    }
    agent()->flush();

    Http::assertSent(fn (Request $request) => count(envelopeOf($request)['events']) === 3);
});

test('long running processes send a batch once it is full', function () {
    config(['monitor.batch_size' => 3]);

    foreach (range(1, 3) as $index) {
        agent()->record(['type' => 'log', 'message' => "event {$index}"]);
    }

    Http::assertSentCount(1);
    expect(agent()->pending())->toBe(0);
});

test('long running processes send a batch once the interval passes', function () {
    agent()->record(['type' => 'log', 'message' => 'early']);
    Http::assertNothingSent();

    $this->travel(11)->seconds();
    agent()->record(['type' => 'log', 'message' => 'late']);

    Http::assertSent(fn (Request $request) => count(envelopeOf($request)['events']) === 2);
});

test('events recorded while sending are ignored', function () {
    Http::fake(function () {
        agent()->record(['type' => 'log', 'message' => 'recursion']);

        return Http::response(['accepted' => 1], 202);
    });
    agent()->record(['type' => 'log', 'message' => 'original']);

    agent()->flush();

    expect(agent()->pending())->toBe(0);
});

test('timestamps are UTC with microseconds', function () {
    expect(Timestamp::now())->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
});
