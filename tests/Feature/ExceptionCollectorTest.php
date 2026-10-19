<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;
use Postingher\MonitorAgent\Collectors\ExceptionCollector;
use Postingher\MonitorAgent\Support\StackTraceFormatter;
use Postingher\MonitorAgent\Tests\Fixtures\DecoratedExceptionHandler;
use Postingher\MonitorAgent\Tests\Fixtures\IgnoredException;
use Postingher\MonitorAgent\Trace;

beforeEach(function () {
    fakePanel();
    app()->instance(StackTraceFormatter::class, new StackTraceFormatter(dirname(__DIR__, 2), 50));
});

test('reports exceptions with relative paths and in-app frames', function () {
    $exception = new RuntimeException('Payment gateway timed out', 504);

    report($exception);

    [$event] = sentEventsOfType('exception');
    expect($event)->toMatchArray([
        'type' => 'exception',
        'trace_id' => app(Trace::class)->id(),
        'class' => RuntimeException::class,
        'message' => 'Payment gateway timed out',
        'code' => '504',
        'file' => 'tests/Feature/ExceptionCollectorTest.php',
        'line' => $exception->getLine(),
    ])->and($event['occurred_at'])->toEndWith('Z')
        ->and($event['trace'][0])->toMatchArray([
            'file' => 'tests/Feature/ExceptionCollectorTest.php',
            'line' => $exception->getLine(),
            'in_app' => true,
        ])
        ->and(collect($event['trace'])->contains(fn (array $frame) => $frame['in_app'] === false))->toBeTrue();
});

test('keeps the application reporting the exception as usual', function () {
    report(new RuntimeException('Still logged'));

    $logs = sentEventsOfType('log');

    expect(array_column($logs, 'message'))->toContain('Still logged');
});

test('adds the request to exceptions thrown while handling one', function () {
    Route::get('/checkout/{order}', fn () => throw new RuntimeException('Boom'))->name('checkout');

    $this->get('/checkout/7?coupon=SECRET');

    expect(sentEventsOfType('exception')[0]['context']['request'])->toBe([
        'method' => 'GET',
        'url' => 'checkout/7',
        'route' => 'checkout',
    ]);
});

test('adds the authenticated user id', function () {
    $user = new User;
    $user->forceFill(['id' => 42]);
    $this->actingAs($user);

    report(new RuntimeException('Boom'));

    expect(sentEventsOfType('exception')[0]['context']['user'])->toBe(['id' => 42]);
});

test('skips exceptions the application does not report', function () {
    app(ExceptionHandler::class)->dontReport([IgnoredException::class]);

    report(new IgnoredException('Ignored'));
    report(new RuntimeException('Reported'));

    expect(array_column(sentEventsOfType('exception'), 'message'))->toBe(['Reported']);
});

test('reports once when another package decorates the exception handler', function () {
    $original = app(ExceptionHandler::class);
    app()->singleton(ExceptionHandler::class, fn () => new DecoratedExceptionHandler($original));
    app(ExceptionHandler::class);

    report(new RuntimeException('Once'));

    expect(sentEventsOfType('exception'))->toHaveCount(1);
});

test('database errors are sent without the bound values', function () {
    $exception = new QueryException(
        'sqlite',
        'insert into users (email, password) values (?, ?)',
        ['ana@example.com', '$2y$12$hashedsecret'],
        new PDOException('UNIQUE constraint failed: users.email'),
    );

    report($exception);

    $events = sentEvents();
    $sent = json_encode($events);
    expect($sent)->not->toContain('ana@example.com')
        ->and($sent)->not->toContain('hashedsecret')
        ->and(collect($events)->firstWhere('type', 'exception')['message'])
        ->toBe('UNIQUE constraint failed: users.email (SQL: insert into users (email, password) values (?, ?))');
});

test('long class names and file paths are cut to what the panel stores', function () {
    $exception = new RuntimeException('Boom');
    $formatter = Mockery::mock(StackTraceFormatter::class);
    $formatter->shouldReceive('relativePath')->andReturn('/'.str_repeat('a', 300).'.php');
    $formatter->shouldReceive('format')->andReturn([]);
    app()->instance(StackTraceFormatter::class, $formatter);
    app()->forgetInstance(ExceptionCollector::class);

    report($exception);

    expect(strlen(sentEventsOfType('exception')[0]['file']))->toBe(255);
});

test('secret route parameters are kept out of the reported url', function () {
    Route::get('/reset-password/{token}', fn () => throw new RuntimeException('Boom'))->name('password.reset');

    $this->get('/reset-password/super-secret-token');

    $request = sentEventsOfType('exception')[0]['context']['request'];
    expect($request['url'])->toBe('reset-password/{token}')
        ->and(json_encode(sentEvents()))->not->toContain('super-secret-token');
});
