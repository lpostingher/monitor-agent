<?php

namespace Postingher\MonitorAgent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class HttpTransport
{
    private const COOLDOWN_KEY = 'monitor-agent:cooldown';

    /**
     * Send a batch of events to the panel, ignoring any failure.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    public function send(array $events): void
    {
        try {
            if (Cache::has(self::COOLDOWN_KEY)) {
                return;
            }

            $payload = json_encode([
                'version' => 1,
                'environment' => app()->environment(),
                'server' => gethostname() ?: null,
                'events' => $events,
            ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            $response = Http::withToken((string) config('monitor-agent.token'))
                ->withHeaders(['Content-Encoding' => 'gzip', 'Accept' => 'application/json'])
                ->connectTimeout((int) config('monitor-agent.connect_timeout'))
                ->timeout((int) config('monitor-agent.timeout'))
                ->withBody((string) gzencode((string) $payload), 'application/json')
                ->post(rtrim((string) config('monitor-agent.url'), '/').'/api/ingest');

            if ($response->serverError() || $response->tooManyRequests()) {
                $this->coolDown();
            }
        } catch (Throwable) {
            $this->coolDown();
        }
    }

    /**
     * Stop sending for a while, so a failing panel never costs every request a timeout.
     */
    private function coolDown(): void
    {
        try {
            Cache::put(self::COOLDOWN_KEY, true, (int) config('monitor-agent.cooldown'));
        } catch (Throwable) {
            // A broken cache store must not affect the monitored application either.
        }
    }
}
