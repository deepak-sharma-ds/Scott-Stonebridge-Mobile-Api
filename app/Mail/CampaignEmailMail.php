<?php

namespace App\Mail;

use App\Models\CampaignDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CampaignEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CampaignDelivery $delivery) {}

    public function envelope(): Envelope
    {
        $subject = $this->delivery->campaignProduct?->email_subject
            ?: 'A Special Message from Scott Stonebridge';

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.campaign-email',
            with: [
                'delivery' => $this->delivery,
                'customerName' => $this->delivery->customer_name ?: 'Dear Friend',
                'campaignBody' => (string) $this->delivery->campaignProduct?->response?->ai_response,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
