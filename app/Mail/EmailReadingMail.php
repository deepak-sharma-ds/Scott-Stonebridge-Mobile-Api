<?php

namespace App\Mail;

use App\Models\EmailReadingDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailReadingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public EmailReadingDelivery $delivery) {}

    public function envelope(): Envelope
    {
        $subject = $this->delivery->product?->email_subject
            ?: 'Your Personal Reading from Scott Stonebridge';

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $view = $this->delivery->product?->email_view
            ?: (string) config('email_reading.default_view', 'mail.email-reading');

        return new Content(
            view: $view,
            with: [
                'delivery' => $this->delivery,
                'product' => $this->delivery->product,
                'customerName' => $this->delivery->customer_name ?: 'Dear Friend',
                'readingBody' => (string) $this->delivery->ai_response,
                'questions' => (array) $this->delivery->questions,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
