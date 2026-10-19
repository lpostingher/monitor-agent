<?php

use Postingher\MonitorAgent\Support\StackTraceFormatter;

test('makes application paths relative and flags vendor frames', function () {
    $formatter = new StackTraceFormatter('/var/www/html', 50);

    expect($formatter->relativePath('/var/www/html/app/Services/Billing.php'))->toBe('app/Services/Billing.php')
        ->and($formatter->isInApp('/var/www/html/app/Services/Billing.php'))->toBeTrue()
        ->and($formatter->isInApp('/var/www/html/vendor/laravel/framework/src/Foo.php'))->toBeFalse()
        ->and($formatter->relativePath('/usr/share/php/Other.php'))->toBe('/usr/share/php/Other.php')
        ->and($formatter->isInApp('/usr/share/php/Other.php'))->toBeFalse();
});

test('lists the throw location first and respects the frame limit', function () {
    $basePath = dirname(__DIR__, 2);
    $exception = new RuntimeException('Boom');

    $frames = (new StackTraceFormatter($basePath, 2))->format($exception);

    expect($frames)->toHaveCount(2)
        ->and($frames[0]['file'])->toBe('tests/Unit/StackTraceFormatterTest.php')
        ->and($frames[0]['line'])->toBe($exception->getLine())
        ->and($frames[0]['in_app'])->toBeTrue()
        ->and($frames[1])->toHaveKeys(['file', 'line', 'class', 'function', 'in_app']);
});
