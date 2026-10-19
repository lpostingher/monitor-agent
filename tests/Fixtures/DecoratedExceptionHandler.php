<?php

namespace Postingher\MonitorAgent\Tests\Fixtures;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Wraps the application's handler the way packages such as Collision do in the console.
 */
class DecoratedExceptionHandler implements ExceptionHandler
{
    public function __construct(private ExceptionHandler $handler) {}

    public function report(Throwable $e)
    {
        $this->handler->report($e);
    }

    public function shouldReport(Throwable $e)
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->handler->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e)
    {
        $this->handler->renderForConsole($output, $e);
    }

    public function reportable(callable $reportUsing): mixed
    {
        return $this->handler->reportable($reportUsing);
    }

    public function __call(string $method, array $parameters): mixed
    {
        return $this->handler->{$method}(...$parameters);
    }
}
