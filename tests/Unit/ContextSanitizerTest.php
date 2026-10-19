<?php

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
