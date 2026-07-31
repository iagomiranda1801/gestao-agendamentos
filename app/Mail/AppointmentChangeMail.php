<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AppointmentChangeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $subjectText,
        public string $bodyText,
    ) {}

    public function build(): static
    {
        return $this
            ->subject($this->subjectText)
            ->text('emails.appointment-change');
    }
}
