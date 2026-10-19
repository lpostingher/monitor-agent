<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => fakePanel());

test('sends a deploy marker immediately', function () {
    $this->artisan('monitor:deploy', ['--ref' => 'abc1234'])->assertSuccessful();

    Http::assertSent(function (Request $request) {
        $events = json_decode(gzdecode($request->body()), true)['events'];

        return count($events) === 1
            && $events[0]['type'] === 'deploy'
            && $events[0]['ref'] === 'abc1234'
            && str_ends_with($events[0]['deployed_at'], 'Z');
    });
});

test('requires a ref', function () {
    $this->artisan('monitor:deploy')->assertFailed();

    Http::assertNothingSent();
});

test('does nothing when the agent is disabled', function () {
    $this->refreshApplicationWithToken(null);
    fakePanel();

    $this->artisan('monitor:deploy', ['--ref' => 'abc1234'])->assertSuccessful();

    Http::assertNothingSent();
});
