<?php

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Postingher\MonitorAgent\Tests\Fixtures\BrokenChannelNotification;
use Postingher\MonitorAgent\Tests\Fixtures\InvoiceMail;
use Postingher\MonitorAgent\Tests\Fixtures\InvoiceReady;
use Postingher\MonitorAgent\Tests\Fixtures\OrderShipped;

beforeEach(function () {
    fakePanel();
    config(['mail.default' => 'array']);
});

test('mail notifications are reported once, by class and channel, without recipients', function () {
    Notification::route('mail', 'ana@example.com')->notify(new OrderShipped);

    $events = sentEventsOfType('notification');
    expect($events)->toHaveCount(1)
        ->and($events[0])->toMatchArray(['class' => OrderShipped::class, 'channel' => 'mail', 'status' => 'sent'])
        ->and($events[0]['sent_at'])->toEndWith('Z')
        ->and(json_encode(sentEvents()))->not->toContain('ana@example.com');
});

test('a notification whose mail is a mailable is still reported once', function () {
    Notification::route('mail', 'ana@example.com')->notify(new InvoiceReady);

    expect(sentEventsOfType('notification'))->toHaveCount(1)
        ->and(sentEventsOfType('notification')[0]['class'])->toBe(InvoiceReady::class);
});

test('mailables sent directly are reported on the mail channel', function () {
    Mail::to('ana@example.com')->send(new InvoiceMail);

    expect(sentEventsOfType('notification')[0])
        ->toMatchArray(['class' => InvoiceMail::class, 'channel' => 'mail', 'status' => 'sent']);
});

test('failed notifications are reported as failed', function () {
    try {
        Notification::route('mail', 'ana@example.com')->notify(new BrokenChannelNotification);
    } catch (RuntimeException) {
    }

    expect(sentEventsOfType('notification')[0])
        ->toMatchArray(['class' => BrokenChannelNotification::class, 'status' => 'failed']);
});
