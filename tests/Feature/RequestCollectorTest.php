<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Postingher\MonitorAgent\Agent;

beforeEach(fn () => fakePanel());

test('reports handled requests with their route, status and duration', function () {
    Route::post('/checkout/{order}', function () {
        Log::info('Charging');

        return response('ok', 201);
    });

    $this->post('/checkout/7?coupon=SECRET')->assertCreated();

    $events = collect(sentEvents());
    $request = $events->firstWhere('type', 'request');
    expect($request)->toMatchArray([
        'method' => 'POST',
        'status' => 201,
        'route_uri' => 'checkout/{order}',
        'action' => 'Closure',
        'url' => 'checkout/7',
        'user_id' => null,
        'trace_id' => $events->firstWhere('type', 'log')['trace_id'],
    ])->and($request['duration_ms'])->toBeGreaterThanOrEqual(0)
        ->and($request['started_at'])->toEndWith('Z');
});

test('includes the id of the authenticated user', function () {
    Route::get('/me', fn () => 'ok');
    $user = new User;
    $user->forceFill(['id' => 42]);

    $this->actingAs($user)->get('/me');

    expect(sentEventsOfType('request')[0]['user_id'])->toBe('42');
});

test('sends the route pattern when the url carries a secret parameter', function () {
    Route::get('/reset-password/{token}', fn () => 'ok');

    $this->get('/reset-password/super-secret-token');

    expect(sentEventsOfType('request')[0]['url'])->toBe('reset-password/{token}');
});

test('requests without a matching route are sent without their path', function () {
    $this->get('/wp-admin/install.php')->assertNotFound();

    expect(sentEventsOfType('request')[0])->toMatchArray([
        'status' => 404,
        'route_uri' => null,
        'url' => null,
    ]);
});

test('ignores the health check route', function () {
    Route::get('/up', fn () => 'ok');

    $this->get('/up');
    app(Agent::class)->flush();

    expect(app(Agent::class)->pending())->toBe(0);
    Http::assertNothingSent();
});
