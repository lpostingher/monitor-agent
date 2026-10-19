<?php

namespace Postingher\MonitorAgent\Collectors;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Support\Timestamp;
use Postingher\MonitorAgent\Trace;
use Throwable;

/**
 * Reports notifications by class and channel, and mailables sent directly.
 * Never recipients, subjects or content.
 */
class NotificationCollector
{
    /**
     * Whether a mail notification is being sent, so the one mail it produces is not counted again.
     * Consumed by that mail, so a send that never finishes can hide at most one mailable.
     */
    private bool $sendingNotification = false;

    public function __construct(
        private Agent $agent,
        private Trace $trace,
    ) {}

    public function sending(NotificationSending $event): void
    {
        $this->sendingNotification = $event->channel === 'mail';
    }

    public function sent(NotificationSent $event): void
    {
        $this->sendingNotification = false;
        $this->record($event->notification::class, (string) $event->channel, 'sent');
    }

    public function failed(NotificationFailed $event): void
    {
        $this->sendingNotification = false;
        $this->record($event->notification::class, (string) $event->channel, 'failed');
    }

    /**
     * Record a mailable sent directly; mail produced by a notification is counted by the notification.
     */
    public function messageSent(MessageSent $event): void
    {
        if (isset($event->data['__laravel_notification'])) {
            $this->sendingNotification = false;

            return;
        }

        if ($this->sendingNotification) {
            $this->sendingNotification = false;

            return;
        }

        $this->record((string) ($event->data['__laravel_mailable'] ?? 'Mail'), 'mail', 'sent');
    }

    private function record(string $class, string $channel, string $status): void
    {
        try {
            $this->agent->record([
                'type' => 'notification',
                'trace_id' => $this->trace->id(),
                'sent_at' => Timestamp::now(),
                'class' => mb_strcut($class, 0, 255),
                'channel' => mb_strcut($channel, 0, 255),
                'status' => $status,
            ]);
        } catch (Throwable) {
            // Never let monitoring break the application.
        }
    }
}
