<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Flush the agent and get every event sent to the faked panel.
 *
 * @return array<int, array<string, mixed>>
 */
function sentEvents(): array
{
    app(Agent::class)->flush();

    $events = [];
    Http::assertSent(function (Request $request) use (&$events) {
        $events = [...$events, ...json_decode(gzdecode($request->body()), true)['events']];

        return true;
    });

    return $events;
}

/**
 * Get the sent events of one type.
 *
 * @return array<int, array<string, mixed>>
 */
function sentEventsOfType(string $type): array
{
    return array_values(array_filter(sentEvents(), fn (array $event) => $event['type'] === $type));
}

/**
 * Fake the panel, replacing any earlier fake so the given response always applies.
 */
function fakePanel(mixed $response = null): void
{
    Http::swap(new Factory(app('events')));
    Http::fake($response ?? ['*' => Http::response(['accepted' => 1], 202)]);
}

/**
 * Flush the agent and get every event sent, or none when nothing was sent.
 *
 * @return array<int, array<string, mixed>>
 */
function sentEventsOrNone(): array
{
    app(Agent::class)->flush();

    return Http::recorded()
        ->flatMap(fn (array $pair) => json_decode(gzdecode($pair[0]->body()), true)['events'])
        ->all();
}
