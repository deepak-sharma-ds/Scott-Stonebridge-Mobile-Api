<?php

namespace App\Mail;

use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Support\InlineEmailStyles;
use App\Support\PlainTextMailBody;
use App\Support\TemplatePlaceholder;
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
        $product = $this->delivery->product;
        $view = $product?->email_view
            ?: (string) config('email_reading.default_view', 'mail.email-reading');
        $customerName = $this->delivery->customer_name ?: 'Dear Friend';
        $vars = [
            'productTitle' => $product?->name ?: 'your reading',
            'customerName' => $customerName,
        ];

        return new Content(
            view: $view,
            with: [
                'delivery' => $this->delivery,
                'product' => $product,
                'customerName' => $customerName,
                'readingBody' => PlainTextMailBody::toHtml((string) $this->delivery->ai_response),
                'questions' => (array) $this->delivery->questions,
                'headerImageUrl' => $this->headerImageUrl($product),
                'emailContent' => InlineEmailStyles::apply(TemplatePlaceholder::render($product?->email_content, $vars, $this->defaultContent())),
                'emailFooter' => InlineEmailStyles::apply(TemplatePlaceholder::render($product?->email_footer, $vars, $this->defaultFooter())),
            ],
        );
    }

    private function headerImageUrl(?EmailReadingProduct $product): string
    {
        return $product?->header_image
            ? asset('storage/reading-header-images/'.$product->header_image)
            : asset('storage/configuration-images/'.config('Site.logo'));
    }

    private function defaultContent(): string
    {
        return <<<'TEXT'
            <p>Thank you for trusting me with your {{ $productTitle }}. Below, you'll find the insights and messages specifically drawn for you.</p>
            TEXT;
    }

    private function defaultFooter(): string
    {
        return <<<'TEXT'
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
            TEXT;
    }

    public function attachments(): array
    {
        return [];
    }
}
