<?php

namespace Postingher\MonitorAgent\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class SyncStock implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('Stock API timed out');
    }
}
