<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Postingher\MonitorAgent\Agent;

/**
 * Fake the monitored app's own HTTP calls while keeping the panel faked.
 */
function fakeApis(): void
{
    Http::swap(new Factory(app('events')));
    Http::fake([
        'monitor.test/*' => Http::response(['accepted' => 1], 202),
        'api.stripe.com/*' => Http::response(['id' => 'ch_1'], 201),
        'api.down.test/*' => Http::failedConnection(),
    ]);
}

/**
 * Events sent to the panel, read from the faked panel requests only.
 *
 * @return array<int, array<string, mixed>>
 */
function panelEvents(): array
{
    app(Agent::class)->flush();

    return Http::recorded(fn (Request $request) => str_contains($request->url(), 'monitor.test'))
        ->flatMap(fn (array $pair) => json_decode(gzdecode($pair[0]->body()), true)['events'])
        ->all();
}

beforeEach(fn () => fakeApis());

test('reports outgoing requests without their query string', function () {
    Http::post('https://api.stripe.com/v1/charges?api_key=secret', ['amount' => 100]);

    $call = collect(panelEvents())->firstWhere('type', 'outgoing_request');

    expect($call)->toMatchArray([
        'method' => 'POST',
        'host' => 'api.stripe.com',
        'url' => 'https://api.stripe.com/v1/charges',
        'status' => 201,
    ])->and($call['sent_at'])->toEndWith('Z')
        ->and(json_encode($call))->not->toContain('secret');
});

test('reports connection failures without a status', function () {
    try {
        Http::get('https://api.down.test/health');
    } catch (ConnectionException) {
    }

    expect(collect(panelEvents())->firstWhere('type', 'outgoing_request'))
        ->toMatchArray(['host' => 'api.down.test', 'status' => null]);
});

test('the agent does not report its own calls to the panel', function () {
    app(Agent::class)->record(['type' => 'log', 'message' => 'x']);
    app(Agent::class)->flush();
    app(Agent::class)->flush();

    expect(app(Agent::class)->pending())->toBe(0);
    Http::assertSentCount(1);
});

test('token-like path segments are masked', function () {
    Http::fake(['hooks.slack.com/*' => Http::response('ok'), 'api.telegram.org/*' => Http::response('ok')]);

    Http::post('https://hooks.slack.com/services/T000/B000/XXXXXXXXXXXXXXXXXXXXXXXX');
    Http::post('https://api.telegram.org/bot123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11/sendMessage');

    $urls = collect(panelEvents())->where('type', 'outgoing_request')->pluck('url')->all();
    expect($urls)->toBe([
        'https://hooks.slack.com/services/T000/B000/*',
        'https://api.telegram.org/*/sendMessage',
    ]);
});
