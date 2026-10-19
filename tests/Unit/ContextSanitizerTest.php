<?php

use Illuminate\Contracts\Support\Arrayable;
use Postingher\MonitorAgent\Support\ContextSanitizer;

function sanitizer(int $maxBytes = 10240): ContextSanitizer
{
    return new ContextSanitizer(['password', 'authorization', 'token'], $maxBytes);
}

test('redacts sensitive keys at any depth, case-insensitively', function () {
    $context = sanitizer()->sanitize([
        'email' => 'ana@example.com',
        'Authorization' => 'Bearer abc',
        'form' => ['user_password' => 'secret', 'name' => 'Ana'],
    ]);

    expect($context)->toBe([
        'email' => 'ana@example.com',
        'Authorization' => '[redacted]',
        'form' => ['user_password' => '[redacted]', 'name' => 'Ana'],
    ]);
});

test('turns objects, exceptions, resources and closures into json safe values', function () {
    $serializable = new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return ['id' => 7, 'token' => 'abc'];
        }
    };

    $context = sanitizer()->sanitize([
        'serializable' => $serializable,
        'plain' => new ArrayObject,
        'exception' => new RuntimeException('Boom'),
        'resource' => fopen('php://memory', 'r'),
        'closure' => fn () => null,
    ]);

    expect($context)->toBe([
        'serializable' => ['id' => 7, 'token' => '[redacted]'],
        'plain' => '[object ArrayObject]',
        'exception' => ['class' => 'RuntimeException', 'message' => 'Boom'],
        'resource' => '[resource]',
        'closure' => '[closure]',
    ]);
});

test('stops at a maximum depth so circular structures cannot loop', function () {
    $node = new stdClass;
    $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'too deep']]]]]];

    $context = sanitizer()->sanitize(['deep' => $deep, 'node' => $node]);

    expect($context['deep']['a']['b']['c']['d'])->toBe('[depth]')
        ->and($context['node'])->toBe('[object stdClass]');
});

test('invalid utf-8 still produces valid json', function () {
    $context = sanitizer()->sanitize(['binary' => "caf\xE9"]);

    expect(json_encode($context))->not->toBeFalse();
});

test('oversized context is replaced by a truncation marker', function () {
    $context = sanitizer(100)->sanitize(['big' => str_repeat('a', 500)]);

    expect($context['_truncated'])->toBeTrue()
        ->and($context['_bytes'])->toBeGreaterThan(100);
});

test('empty context becomes null', function () {
    expect(sanitizer()->sanitize([]))->toBeNull();
});

test('keeps only the first items of large arrays and collections', function () {
    $context = sanitizer(1_000_000)->sanitize([
        'ids' => range(1, 1000),
        'rows' => collect(range(1, 1000)),
    ]);

    expect($context['ids'])->toHaveCount(51)
        ->and($context['ids']['…'])->toBe('950 more')
        ->and($context['rows'])->toHaveCount(51)
        ->and($context['rows']['…'])->toBe('950 more');
});

test('large collections are not converted in full', function () {
    $converted = 0;
    $rows = collect(range(1, 1000))->map(fn ($id) => new class($id, $converted) implements Arrayable
    {
        public function __construct(private int $id, private int &$converted) {}

        public function toArray(): array
        {
            $this->converted++;

            return ['id' => $this->id];
        }
    });

    sanitizer(1_000_000)->sanitize(['rows' => $rows]);

    expect($converted)->toBeLessThanOrEqual(50);
});

test('objects that serialize to themselves stop at the depth limit', function () {
    $loop = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            return $this;
        }
    };

    expect(sanitizer()->sanitize(['loop' => $loop]))->toBe(['loop' => '[depth]']);
});
