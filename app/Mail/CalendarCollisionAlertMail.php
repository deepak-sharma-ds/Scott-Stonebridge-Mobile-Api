<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ScheduledMeeting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CalendarCollisionAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ScheduledMeeting $meeting,
        public string $targetDateStr,
        public string $targetTimeStr,
        public string $originalDatetimeStr,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Calendar Move Collision Prevented - Reverted',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: "<p>Calendar Move Collision Prevented: You attempted to move the meeting with <strong>{$this->meeting->name}</strong> to {$this->targetDateStr} {$this->targetTimeStr}, but that slot is already occupied. The event was automatically reverted back to its original time ({$this->originalDatetimeStr}).</p>",
        );
    }
}
