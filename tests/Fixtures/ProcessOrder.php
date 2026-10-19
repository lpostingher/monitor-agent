<?php

namespace Postingher\MonitorAgent\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessOrder implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::info('Processing order');
    }
}
