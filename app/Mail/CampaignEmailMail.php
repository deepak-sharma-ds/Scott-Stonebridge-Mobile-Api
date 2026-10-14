<?php

namespace App\Mail;

use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Support\EmailBodyHtml;
use App\Support\InlineEmailStyles;
use App\Support\TemplatePlaceholder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class CampaignEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  bool  $tracked  false for admin previews of a saved delivery, which
     *                         must not tag the message or embed the open-tracking pixel
     */
    public function __construct(public CampaignDelivery $delivery, public bool $tracked = true) {}

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
        $product = $this->delivery->campaignProduct;
        $vars = [
            'productTitle' => e($product?->product_title ?: 'your reading'),
            'campaignName' => e($product?->marketingCampaign?->name ?: ''),
        ];

        return new Content(
            view: 'mail.campaign-email',
            with: [
                'delivery' => $this->delivery,
                'trackingToken' => $this->trackingToken(),
                'customerName' => $this->delivery->customer_name ?: 'Dear Friend',
                'campaignBody' => EmailBodyHtml::render((string) $product?->response?->body),
                'headerImageUrl' => $this->headerImageUrl($product),
                'emailContent' => $this->renderTemplateField($product?->email_content, $vars, $this->defaultContent()),
                'emailFooter' => $this->renderTemplateField($product?->email_footer, $vars, $this->defaultFooter()),
            ],
        );
    }

    private function headerImageUrl(?CampaignProduct $product): string
    {
        return $product?->header_image
            ? asset('storage/campaign-header-images/'.$product->header_image)
            : asset('storage/configuration-images/'.config('Site.logo'));
    }

    /**
     * @param  array<string,mixed>  $vars
     */
    private function renderTemplateField(?string $template, array $vars, string $default): string
    {
        return InlineEmailStyles::apply(TemplatePlaceholder::render($template, $vars, $default));
    }

    private function defaultContent(): string
    {
        return <<<'BLADE'
            <p>Thank you for trusting me with your {{ $productTitle }}. Below, you'll find the insights and messages specifically drawn for you.</p>
            BLADE;
    }

    private function defaultFooter(): string
    {
        return <<<'BLADE'
            <p>📱 If you feel there's more to uncover, you can now get a personal reply sent straight to your phone.</p>
            <p>Just text SCOTT to 85358</p>
            <p>Or you can:</p>
            <p>📩 <a href="#">CLICK HERE TO BEGIN</a></p>
            <p>Whether it's love, your future, or something you've been quietly carrying, a fresh insight could be just one message away. These text replies are quick, private, and often arrive at exactly the moment you need them most.</p>
            <p>⚠️ Please note: I can't give guidance on health, pregnancy, legal or financial matters. 18+ only. £1 per message, max 3 replies.</p>
            <p><strong>💗 Supporting a Cause That Matters</strong><br>Your reading also helps raise vital funds for the Motor Neurone Disease Association, a cause close to my heart. Thank you for being part of something meaningful.</p>
            <p><strong>Follow Me for Daily Spiritual Insights</strong><br>Be sure to stay connected! Follow me on <a href="#">INSTAGRAM</a> for inspiration, live updates, and special offers that you won't want to miss.</p>
            <p>Tap here to join: <a href="#">INSTAGRAM</a></p>
            <p>Thank you once again for choosing my service. I look forward to connecting with you again soon!</p>
            <p>With love and light,</p>
            <p>Scott xx</p>
            BLADE;
    }

    /**
     * Tags a customer delivery so the MessageSent listener can attach Delivery
     * Proof to it. Unsaved preview and "Send Test to Me" deliveries have no
     * row to update, so they carry no tracking headers.
     */
    public function headers(): Headers
    {
        if ($this->trackingToken() === null) {
            return new Headers;
        }

        return new Headers(messageId: $this->newMessageId(), text: [
            'X-Delivery-Type' => 'campaign',
            'X-Delivery-Id' => (string) $this->delivery->getKey(),
        ]);
    }

    /**
     * An explicit RFC Message-ID, so the stored Delivery Proof is exactly the
     * identifier the message carries (and Google's Email Log Search can find).
     */
    private function newMessageId(): string
    {
        return Str::uuid()->toString().'@'.(Str::after((string) config('mail.from.address'), '@') ?: 'localhost');
    }

    /**
     * Only a saved, customer-facing delivery is tracked; unsaved preview and
     * "Send Test to Me" deliveries have no row to record against.
     */
    private function trackingToken(): ?string
    {
        return $this->tracked && $this->delivery->exists
            ? $this->delivery->tracking_token
            : null;
    }

    public function attachments(): array
    {
        return [];
    }
}
