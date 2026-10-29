<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CalendarWatchRenewalFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $calendarName,
        public string $errorMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Google Calendar Watch Renewal Failed - Action Required',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: "<p>Google Calendar webhook watch renewal failed for <strong>{$this->calendarName}</strong>.</p><p>Error: {$this->errorMessage}</p><p>Please re-authenticate your Google Calendar integration in the admin panel.</p>",
        );
    }
}
