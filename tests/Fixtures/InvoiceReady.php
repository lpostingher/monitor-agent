<?php

namespace Postingher\MonitorAgent\Tests\Fixtures;

use Illuminate\Notifications\Notification;

class InvoiceReady extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): InvoiceMail
    {
        return (new InvoiceMail)->to('ana@example.com');
    }
}
