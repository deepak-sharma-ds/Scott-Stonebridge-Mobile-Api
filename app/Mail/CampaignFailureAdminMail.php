<?php

namespace App\Mail;

use App\Models\CampaignDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CampaignFailureAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CampaignDelivery $delivery,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Scott Stonebridge] Campaign Email Failed — Order #'.$this->delivery->shopify_order_id,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.campaign-failure-admin',
            with: [
                'delivery' => $this->delivery,
                'reason' => $this->reason,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
