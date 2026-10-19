<?php

namespace Postingher\MonitorAgent\Support;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
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
    private function sanitizeArray(array $values, int $depth): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            $sanitized[$key] = $this->isSensitive((string) $key)
                ? '[redacted]'
                : $this->sanitizeValue($value, $depth);
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        return match (true) {
            is_array($value) => $depth >= self::MAX_DEPTH ? '[depth]' : $this->sanitizeArray($value, $depth + 1),
            $value instanceof Throwable => ['class' => $value::class, 'message' => ExceptionMessage::for($value)],
            $value instanceof Closure => '[closure]',
            $value instanceof JsonSerializable => $this->sanitizeValue($value->jsonSerialize(), $depth),
            $value instanceof Arrayable => $this->sanitizeValue($value->toArray(), $depth),
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
