<?php

use Illuminate\Support\Facades\Cache;
use Postingher\MonitorAgent\Collectors\CacheCollector;

beforeEach(fn () => fakePanel());

test('cache operations are summed per normalized key and sent once per key', function () {
    Cache::put('user:42:cart', 'x');
    Cache::get('user:42:cart');
    Cache::get('user:7:cart');
    Cache::get('user:7:cart');
    Cache::forget('user:42:cart');

    $events = sentEventsOfType('cache');
    expect($events)->toHaveCount(1)
        ->and($events[0])->toMatchArray(['key' => 'user:*:cart', 'hits' => 1, 'misses' => 2, 'writes' => 1, 'forgets' => 1])
        ->and($events[0]['recorded_at'])->toEndWith('Z');
});

test('ids, hashes, tokens and emails never leave the key', function (string $key, string $normalized) {
    expect(CacheCollector::normalize($key))->toBe($normalized);
})->with([
    ['cart:550e8400-e29b-41d4-a716-446655440000', 'cart:*-*-*-*-*'],
    ['prefs:john@example.com', 'prefs:*'],
    ['throttle:5f4dcc3b5aa765d61d8327deb882cf99', 'throttle:*'],
    ['settings.theme', 'settings.theme'],
]);

test('framework and agent keys are ignored', function () {
    Cache::get('illuminate:queue:restart');
    Cache::put('monitor-agent:cooldown', true);

    expect(sentEventsOrNone())->toBe([]);
});

test('keys beyond the limit are summed under one row', function () {
    foreach (range(1, 505) as $index) {
        Cache::get('report:'.strtr((string) $index, '0123456789', 'abcdefghij'));
    }
    Cache::get('another-key-that-overflows');

    $events = collect(sentEventsOfType('cache'));
    expect($events->count())->toBeLessThanOrEqual(501)
        ->and($events->firstWhere('key', '*other*'))->not->toBeNull();
});
