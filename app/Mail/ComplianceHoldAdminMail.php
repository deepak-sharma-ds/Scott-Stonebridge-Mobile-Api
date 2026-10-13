<?php

namespace App\Mail;

use App\Models\EmailReadingDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ComplianceHoldAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public EmailReadingDelivery $delivery) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Scott Stonebridge] Email Reading On Compliance Hold — Order #'.$this->delivery->shopify_order_id,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.compliance-hold-admin',
            with: [
                'delivery' => $this->delivery,
                'product' => $this->delivery->product,
                'reason' => (string) $this->delivery->compliance_flagged_reason,
                'reviewUrl' => route('admin.email_readings.show', $this->delivery),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
