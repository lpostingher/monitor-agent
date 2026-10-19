<?php

namespace Postingher\MonitorAgent\Support;

use Closure;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Enumerable;
use JsonSerializable;
use Throwable;

/**
 * Turns arbitrary log and exception context into small, JSON-safe arrays
 * with sensitive values redacted.
 */
class ContextSanitizer
{
    private const MAX_DEPTH = 5;

    /**
     * Items kept per array or collection; the rest are summarized.
     */
    private const MAX_ITEMS = 50;

    /**
     * @param  array<int, string>  $redactKeys
     */
    public function __construct(private array $redactKeys, private int $maxBytes) {}

    /**
     * Sanitize a context array.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>|null
     */
    public function sanitize(array $context): ?array
    {
        if ($context === []) {
            return null;
        }

        $flags = JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $json = (string) json_encode($this->sanitizeArray($context, 1), $flags);

        if (strlen($json) > $this->maxBytes) {
            return ['_truncated' => true, '_bytes' => strlen($json)];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function sanitizeArray(array $values, int $depth, ?int $total = null): array
    {
        $sanitized = [];

        foreach (array_slice($values, 0, self::MAX_ITEMS, true) as $key => $value) {
            $sanitized[$key] = $this->isSensitive((string) $key)
                ? '[redacted]'
                : $this->sanitizeValue($value, $depth);
        }

        $total ??= count($values);

        if ($total > self::MAX_ITEMS) {
            $sanitized['…'] = ($total - self::MAX_ITEMS).' more';
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ((is_array($value) || is_object($value)) && $depth >= self::MAX_DEPTH) {
            return '[depth]';
        }

        return match (true) {
            is_array($value) => $this->sanitizeArray($value, $depth + 1),
            $value instanceof Throwable => ['class' => $value::class, 'message' => ExceptionMessage::for($value)],
            $value instanceof Closure => '[closure]',
            $value instanceof Enumerable => $this->sanitizeArray(
                $value->take(self::MAX_ITEMS)->all(),
                $depth + 1,
                $value instanceof Countable ? count($value) : null,
            ),
            $value instanceof JsonSerializable => $this->sanitizeValue($value->jsonSerialize(), $depth + 1),
            $value instanceof Arrayable => $this->sanitizeValue($value->toArray(), $depth + 1),
            is_object($value) => '[object '.$value::class.']',
            is_resource($value) || gettype($value) === 'resource (closed)' => '[resource]',
            default => $value,
        };
    }

    /**
     * Determine whether a key names a value that must be redacted.
     */
    public function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->redactKeys as $redactKey) {
            if (str_contains($key, $redactKey)) {
                return true;
            }
        }

        return false;
    }
}
