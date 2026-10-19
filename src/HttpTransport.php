<?php

namespace Postingher\MonitorAgent;

use Illuminate\Support\Facades\Http;
use Throwable;

class HttpTransport
{
    /**
     * Send a batch of events to the panel, ignoring any failure.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    public function send(array $events): void
    {
        try {
            $payload = json_encode([
                'version' => 1,
                'environment' => app()->environment(),
                'server' => gethostname() ?: null,
                'events' => $events,
            ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            Http::withToken((string) config('monitor-agent.token'))
                ->withHeaders(['Content-Encoding' => 'gzip', 'Accept' => 'application/json'])
                ->connectTimeout((int) config('monitor-agent.connect_timeout'))
                ->timeout((int) config('monitor-agent.timeout'))
                ->withBody((string) gzencode((string) $payload), 'application/json')
                ->post(rtrim((string) config('monitor-agent.url'), '/').'/api/ingest');
        } catch (Throwable) {
            // The panel being unreachable must never affect the monitored application.
        }
    }
}
