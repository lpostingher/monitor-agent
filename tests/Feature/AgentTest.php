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

beforeEach(fn () => fakePanel());

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
    fakePanel(fn () => throw new ConnectionException('Connection refused'));
    agent()->record(['type' => 'log', 'message' => 'lost']);

    agent()->flush();

    expect(agent()->pending())->toBe(0);
});

test('a failing panel response never throws', function () {
    fakePanel(['*' => Http::response('down', 500)]);
    agent()->record(['type' => 'log', 'message' => 'lost']);

    agent()->flush();

    expect(agent()->pending())->toBe(0);
});

test('drops events beyond the buffer limit', function () {
    config(['monitor-agent.max_buffer' => 3, 'monitor-agent.batch_size' => 100]);

    foreach (range(1, 5) as $index) {
        agent()->record(['type' => 'log', 'message' => "event {$index}"]);
    }
    agent()->flush();

    Http::assertSent(fn (Request $request) => count(envelopeOf($request)['events']) === 3);
});

test('long running processes send a batch once it is full', function () {
    config(['monitor-agent.batch_size' => 3]);

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
    fakePanel(function () {
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

test('caps the buffer in bytes, keeping room for exceptions after logs fill their share', function () {
    config(['monitor-agent.max_buffer_bytes' => 300_000, 'monitor-agent.batch_size' => 1000]);

    foreach (range(1, 10) as $index) {
        agent()->record(['type' => 'log', 'message' => str_repeat('x', 50_000)]);
    }
    agent()->record(['type' => 'exception', 'message' => 'Still kept']);
    agent()->flush();

    Http::assertSent(function (Request $request) {
        $events = envelopeOf($request)['events'];

        return strlen(gzdecode($request->body())) < 300_000
            && count(array_filter($events, fn ($event) => $event['type'] === 'log')) === 3
            && end($events)['message'] === 'Still kept';
    });
});

test('pauses sending for a while after the panel fails', function () {
    fakePanel(fn () => throw new ConnectionException('Connection timed out'));
    agent()->record(['type' => 'log', 'message' => 'first']);
    agent()->flush();

    fakePanel();
    agent()->record(['type' => 'log', 'message' => 'during cooldown']);
    agent()->flush();
    Http::assertNothingSent();

    $this->travel(31)->seconds();
    agent()->record(['type' => 'log', 'message' => 'after cooldown']);
    agent()->flush();
    Http::assertSentCount(1);
});

test('pauses sending after a server error or rate limit', function (int $status) {
    fakePanel(['*' => Http::response('', $status)]);
    agent()->record(['type' => 'log', 'message' => 'first']);
    agent()->flush();

    agent()->record(['type' => 'log', 'message' => 'second']);
    agent()->flush();

    Http::assertSentCount(1);
})->with([500, 503, 429]);
