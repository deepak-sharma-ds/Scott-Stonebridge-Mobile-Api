<?php

namespace App\Mail;

use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Blade;

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
        $product = $this->delivery->campaignProduct;
        $vars = [
            'productTitle' => $product?->product_title ?: 'your reading',
            'campaignName' => $product?->marketingCampaign?->name ?: '',
        ];

        return new Content(
            view: 'mail.campaign-email',
            with: [
                'delivery' => $this->delivery,
                'customerName' => $this->delivery->customer_name ?: 'Dear Friend',
                'campaignBody' => (string) $product?->response?->body,
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
     * Renders admin-authored copy as a Blade template, mirroring
     * CampaignResponseGenerationService::renderPrompt() so `{{ $productTitle }}`
     * / `{{ $campaignName }}` placeholders work the same way they do there.
     *
     * @param  array<string,mixed>  $vars
     */
    private function renderTemplateField(?string $template, array $vars, string $default): string
    {
        return Blade::render($template ?: $default, $vars);
    }

    private function defaultContent(): string
    {
        return <<<'BLADE'
            Thank you for trusting me with your {{ $productTitle }}. Below, you'll find the insights and messages specifically drawn for you.
            BLADE;
    }

    private function defaultFooter(): string
    {
        return <<<'BLADE'
            📱 If you feel there's more to uncover, you can now get a personal reply sent straight to your phone.

            Just text SCOTT to 85358

            Or you can:

            📩 <a href="#">CLICK HERE TO BEGIN</a>

            Whether it's love, your future, or something you've been quietly carrying, a fresh insight could be just one message away. These text replies are quick, private, and often arrive at exactly the moment you need them most.

            ⚠️ Please note: I can't give guidance on health, pregnancy, legal or financial matters. 18+ only. £1 per message, max 3 replies.

            💗 Supporting a Cause That Matters
            Your reading also helps raise vital funds for the Motor Neurone Disease Association, a cause close to my heart. Thank you for being part of something meaningful.

            Follow Me for Daily Spiritual Insights
            Be sure to stay connected! Follow me on <a href="#">INSTAGRAM</a> for inspiration, live updates, and special offers that you won't want to miss.

            Tap here to join: <a href="#">INSTAGRAM</a>

            Thank you once again for choosing my service. I look forward to connecting with you again soon!

            With love and light,

            Scott xx
            BLADE;
    }

    public function attachments(): array
    {
        return [];
    }
}
