<?php

namespace Postingher\MonitorAgent\Collectors;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Support\Timestamp;
use Postingher\MonitorAgent\Trace;
use Throwable;

/**
 * Reports calls made through Laravel's HTTP client. The agent's own calls to the
 * panel happen while it is flushing, so they are never reported.
 */
class OutgoingRequestCollector
{
    public function __construct(
        private Agent $agent,
        private Trace $trace,
    ) {}

    /**
     * Record a call that got a response.
     */
    public function received(ResponseReceived $event): void
    {
        $totalTime = $event->response->handlerStats()['total_time'] ?? null;

        $this->record($event->request, $event->response->status(), is_numeric($totalTime) ? (float) $totalTime : null);
    }

    /**
     * Record a call that could not connect.
     */
    public function failed(ConnectionFailed $event): void
    {
        $this->record($event->request, null, null);
    }

    /**
     * Mask path segments that look like credentials, such as Slack, Discord or Telegram tokens.
     */
    private function maskTokens(string $path): string
    {
        return (string) preg_replace('#(?<=/)[A-Za-z0-9_:\-]{20,}(?=/|$)#', '*', $path);
    }

    private function record(Request $request, ?int $status, ?float $seconds): void
    {
        try {
            if ($this->agent->isFlushing()) {
                return;
            }

            $parts = parse_url($request->url());
            $host = $parts['host'] ?? '';

            $this->agent->record([
                'type' => 'outgoing_request',
                'trace_id' => $this->trace->id(),
                'sent_at' => Timestamp::now(),
                'method' => $request->method(),
                'host' => mb_strcut($host, 0, 255),
                'url' => mb_strcut(($parts['scheme'] ?? 'https').'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').$this->maskTokens($parts['path'] ?? ''), 0, 255),
                'status' => $status,
                'duration_ms' => $seconds === null ? null : round($seconds * 1000, 2),
            ]);
        } catch (Throwable) {
            // Never let monitoring break the application.
        }
    }
}
