<?php

namespace Postingher\MonitorAgent\Support;

use Throwable;

class StackTraceFormatter
{
    private string $basePath;

    public function __construct(string $basePath, private int $maxFrames)
    {
        $this->basePath = rtrim($basePath, '/').'/';
    }

    /**
     * Format an exception's stack, starting with the location it was thrown from.
     *
     * @return array<int, array{file: ?string, line: ?int, class: ?string, function: ?string, in_app: bool}>
     */
    public function format(Throwable $exception): array
    {
        $frames = [['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$exception->getTrace()];

        return array_map(fn (array $frame): array => [
            'file' => isset($frame['file']) ? $this->relativePath($frame['file']) : null,
            'line' => $frame['line'] ?? null,
            'class' => $frame['class'] ?? null,
            'function' => $frame['function'] ?? null,
            'in_app' => isset($frame['file']) && $this->isInApp($frame['file']),
        ], array_slice($frames, 0, $this->maxFrames));
    }

    /**
     * Get a path relative to the application root, so it is stable across deploy directories.
     */
    public function relativePath(string $file): string
    {
        return str_starts_with($file, $this->basePath) ? substr($file, strlen($this->basePath)) : $file;
    }

    /**
     * Determine whether a file belongs to the application rather than its dependencies.
     */
    public function isInApp(string $file): bool
    {
        return str_starts_with($file, $this->basePath)
            && ! str_starts_with($this->relativePath($file), 'vendor/');
    }
}
