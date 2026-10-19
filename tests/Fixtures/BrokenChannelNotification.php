<?php

namespace Postingher\MonitorAgent\Tests\Fixtures;

use Illuminate\Notifications\Notification;
use RuntimeException;

class BrokenChannelNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return [BrokenChannel::class];
    }
}

class BrokenChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        throw new RuntimeException('Provider down');
    }
}
