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
        public ?string $fromEmail = null,
        public ?string $fromName = null,
    ) {}

    public function build(): static
    {
        $mail = $this
            ->subject($this->subjectText)
            ->text('emails.appointment-change');

        if (filled($this->fromEmail) && filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->from($this->fromEmail, $this->fromName);
        }

        return $mail;
    }
}
