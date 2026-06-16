<?php

namespace App\Mail;

use App\Models\EmailReadingDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReadingFailureAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public EmailReadingDelivery $delivery,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Scott Stonebridge] Email Reading Failed — Order #'.$this->delivery->shopify_order_id,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.reading-failure-admin',
            with: [
                'delivery' => $this->delivery,
                'product' => $this->delivery->product,
                'reason' => $this->reason,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
