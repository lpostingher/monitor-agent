<?php

namespace Postingher\MonitorAgent\Tests\Fixtures;

use Illuminate\Mail\Mailable;

class InvoiceMail extends Mailable
{
    public function build(): static
    {
        return $this->subject('Invoice')->html('<p>Invoice</p>');
    }
}
