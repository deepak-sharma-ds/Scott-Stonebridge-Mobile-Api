<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\AiLead;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Abandon-recovery email triggered when a chat session ends with a captured
 * lead and a non-empty cart. Delivers a short reminder linking back to the
 * cart on the storefront. Rendered via Blade (not Markdown) so the design
 * can match the existing transactional templates in resources/views/mail/.
 *
 * The mailable is built synchronously inside SendAbandonRecoveryEmailJob
 * (which is itself queued), so it does NOT implement ShouldQueue here to
 * avoid double-queueing.
 */
class AbandonRecoveryMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array{role: string, message: string}>  $chatExcerpt
     */
    public function __construct(
        public readonly AiLead $lead,
        public readonly array $chatExcerpt,
        public readonly string $cartUrl,
        public readonly string $shopName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your cart at '.$this->shopName.' is waiting',
        );
    }

    public function content(): Content
    {
        $snapshot = $this->lead->cart_snapshot_json ?? [];

        return new Content(
            view: 'mail.abandon-recovery',
            with: [
                'lead' => $this->lead,
                'name' => $this->lead->name ?: 'there',
                'shop_name' => $this->shopName,
                'cart_url' => $this->cartUrl,
                'item_count' => (int) ($snapshot['item_count'] ?? 0),
                'total_price' => $snapshot['total_price'] ?? null,
                'items' => is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [],
                'chat_excerpt' => $this->chatExcerpt,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
