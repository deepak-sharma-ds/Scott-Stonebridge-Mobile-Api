<?php

namespace Tests\Feature\Listeners;

use App\Mail\CampaignEmailMail;
use App\Mail\EmailReadingMail;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\MarketingCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class CaptureSentEmailMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function readingDelivery(): EmailReadingDelivery
    {
        $product = EmailReadingProduct::first() ?? EmailReadingProduct::create([
            'shopify_product_id' => 111,
            'name' => 'Reading',
            'slug' => 'reading',
            'questions_schema' => [],
            'prompt_template' => 'x',
        ]);

        return EmailReadingDelivery::create([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 501,
            'email_reading_product_id' => $product->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'questions' => [],
            'ai_response' => 'Reading body.',
            'status' => EmailReadingDelivery::STATUS_SENDING,
        ]);
    }

    private function campaignDelivery(): CampaignDelivery
    {
        $campaign = MarketingCampaign::create([
            'campaign_key' => 'spring',
            'name' => 'Spring',
            'status' => MarketingCampaign::STATUS_ACTIVE,
        ]);
        $product = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'shopify_variant_id' => 5001,
            'product_title' => 'Reading',
        ]);

        return CampaignDelivery::create([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 501,
            'campaign_product_id' => $product->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'status' => CampaignDelivery::STATUS_SENDING,
        ]);
    }

    private function lastSentMessageIdHeader(): string
    {
        return app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHeaders()->get('Message-ID')->getId();
    }

    /**
     * What Symfony's SmtpTransport really appends on the first message of a
     * connection: every command (including AUTH PLAIN credentials) and reply.
     */
    private function smtpDialog(string $finalReply): string
    {
        $t = '[2026-10-14T12:00:00.123456+00:00]';

        return "{$t} < 220 smtp.gmail.com ESMTP ready
"
            ."{$t} > EHLO app.example.com
"
            ."{$t} < 250-smtp.gmail.com at your service
"
            ."{$t} < 250 SMTPUTF8
"
            ."{$t} > AUTH PLAIN c2NvdHRAZXhhbXBsZS5jb20Ac2NvdHRAZXhhbXBsZS5jb20AaHVudGVyMi1zZWNyZXQ=
"
            ."{$t} < 235 2.7.0 Accepted
"
            ."{$t} > MAIL FROM:<scott@example.com>
"
            ."{$t} < 250 2.1.0 OK abc.1 - gsmtp
"
            ."{$t} > DATA
"
            ."{$t} < 354 Go ahead abc.1 - gsmtp
"
            ."{$t} < {$finalReply}
";
    }

    private function dispatchSent(int $deliveryId, string $type, string $debug, string $messageId = 'generated@scottstonebridge.com'): void
    {
        $email = (new Email)
            ->from('scott@scottstonebridge.com')
            ->to('buyer@example.com')
            ->subject('Your reading')
            ->text('body');
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);
        $email->getHeaders()->addTextHeader('X-Delivery-Type', $type);
        $email->getHeaders()->addTextHeader('X-Delivery-Id', (string) $deliveryId);

        $symfonySent = new SymfonySentMessage($email, new Envelope(new Address('scott@scottstonebridge.com'), [new Address('buyer@example.com')]));
        $symfonySent->appendDebug($debug);

        event(new MessageSent(new SentMessage($symfonySent)));
    }

    public function test_a_sent_reading_email_records_its_message_id_and_acceptance_time(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $delivery = $this->readingDelivery();

        Mail::to($delivery->customer_email)->send(new EmailReadingMail($delivery));

        $delivery->refresh();
        $this->assertSame($this->lastSentMessageIdHeader(), $delivery->message_id);
        $this->assertSame('2026-10-14 12:00:00', $delivery->dispatched_at->format('Y-m-d H:i:s'));
        $this->assertNull($delivery->send_history);
    }

    public function test_a_sent_campaign_email_records_its_message_id_and_acceptance_time(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $delivery = $this->campaignDelivery();

        Mail::to($delivery->customer_email)->send(new CampaignEmailMail($delivery));

        $delivery->refresh();
        $this->assertSame($this->lastSentMessageIdHeader(), $delivery->message_id);
        $this->assertSame('2026-10-14 12:00:00', $delivery->dispatched_at->format('Y-m-d H:i:s'));
    }

    public function test_the_local_transport_is_recorded_when_there_is_no_smtp_dialog(): void
    {
        $delivery = $this->readingDelivery();

        Mail::to($delivery->customer_email)->send(new EmailReadingMail($delivery));

        $this->assertStringContainsString('array', (string) $delivery->refresh()->smtp_response);
    }

    public function test_the_smtp_server_acknowledgement_is_stored_when_the_transport_reports_one(): void
    {
        $delivery = $this->readingDelivery();

        $this->dispatchSent($delivery->id, 'reading', $this->smtpDialog('250 2.0.0 OK  1760000000 abc.12 - gsmtp'));

        $this->assertSame('250 2.0.0 OK  1760000000 abc.12 - gsmtp', $delivery->refresh()->smtp_response);
    }

    public function test_a_resend_archives_the_previous_dispatch_in_send_history(): void
    {
        $delivery = $this->readingDelivery();
        $delivery->update([
            'message_id' => 'first@scottstonebridge.com',
            'smtp_response' => '250 2.0.0 OK first',
            'dispatched_at' => '2026-10-01 09:30:00',
        ]);

        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->dispatchSent($delivery->id, 'reading', $this->smtpDialog('250 2.0.0 OK second'), 'second@scottstonebridge.com');
        Carbon::setTestNow('2026-10-15 08:00:00');
        $this->dispatchSent($delivery->id, 'reading', $this->smtpDialog('250 2.0.0 OK third'), 'third@scottstonebridge.com');

        $delivery->refresh();
        $this->assertSame('third@scottstonebridge.com', $delivery->message_id);
        $this->assertSame('2026-10-15 08:00:00', $delivery->dispatched_at->format('Y-m-d H:i:s'));
        $history = $delivery->send_history;
        $this->assertCount(2, $history);
        $this->assertSame(['first@scottstonebridge.com', '250 2.0.0 OK first'], [$history[0]['message_id'], $history[0]['smtp_response']]);
        $this->assertSame(['second@scottstonebridge.com', '250 2.0.0 OK second'], [$history[1]['message_id'], $history[1]['smtp_response']]);
        $this->assertSame('2026-10-01 09:30:00', Carbon::parse($history[0]['dispatched_at'])->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-14 12:00:00', Carbon::parse($history[1]['dispatched_at'])->format('Y-m-d H:i:s'));
    }

    public function test_the_tracking_token_survives_a_resend(): void
    {
        $delivery = $this->campaignDelivery();
        $token = $delivery->tracking_token;

        $this->dispatchSent($delivery->id, 'campaign', '250 OK', 'a@x');
        $this->dispatchSent($delivery->id, 'campaign', '250 OK', 'b@x');

        $this->assertSame($token, $delivery->refresh()->tracking_token);
    }

    public function test_emails_without_delivery_headers_are_ignored(): void
    {
        $delivery = $this->readingDelivery();

        Mail::raw('A system email', fn ($message) => $message->to('admin@example.com')->subject('Hi'));

        $this->assertNull($delivery->refresh()->message_id);
    }

    public function test_an_unsaved_preview_send_never_touches_delivery_records(): void
    {
        $delivery = $this->readingDelivery();
        $preview = EmailReadingDelivery::make(['customer_email' => 'admin@example.com', 'ai_response' => 'x']);

        Mail::to('admin@example.com')->send(new EmailReadingMail($preview));

        $this->assertNull($delivery->refresh()->message_id);
    }

    public function test_a_tagged_email_for_a_missing_delivery_is_ignored(): void
    {
        $this->dispatchSent(987654, 'reading', '250 OK');
        $this->dispatchSent(987654, 'campaign', '250 OK');

        $this->assertSame(0, EmailReadingDelivery::count() + CampaignDelivery::count());
    }

    public function test_an_unknown_delivery_type_header_is_ignored(): void
    {
        $delivery = $this->readingDelivery();

        $this->dispatchSent($delivery->id, 'mystery', '250 OK');

        $this->assertNull($delivery->refresh()->message_id);
    }

    public function test_smtp_credentials_from_the_dialog_are_never_stored(): void
    {
        $delivery = $this->readingDelivery();

        $this->dispatchSent($delivery->id, 'reading', $this->smtpDialog('250 2.0.0 OK  1760000000 abc.12 - gsmtp'));

        $stored = $delivery->refresh()->smtp_response;
        $this->assertStringNotContainsString('AUTH', $stored);
        $this->assertStringNotContainsString('c2NvdHRAZXhhbXBsZS5jb20A', $stored);
        $this->assertStringNotContainsString('EHLO', $stored);
        $this->assertSame(1, substr_count($stored, "\n") + 1);
    }

    public function test_a_later_message_on_the_same_connection_stores_its_own_final_reply(): void
    {
        $delivery = $this->readingDelivery();
        $t = '[2026-10-14T12:00:05.000000+00:00]';

        $this->dispatchSent($delivery->id, 'reading', "{$t} > MAIL FROM:<a@b>\n{$t} < 250 2.1.0 OK x\n{$t} > DATA\n{$t} < 354 Go ahead\n{$t} < 250 2.0.0 OK  1760000005 def.34 - gsmtp\n");

        $this->assertSame('250 2.0.0 OK  1760000005 def.34 - gsmtp', $delivery->refresh()->smtp_response);
    }

    public function test_the_stored_message_id_is_the_message_id_header_not_the_transport_id(): void
    {
        $delivery = $this->readingDelivery();
        $email = (new Email)->from('scott@scottstonebridge.com')->to('buyer@example.com')->subject('s')->text('b');
        $email->getHeaders()->addIdHeader('Message-ID', 'rfc-header-id@scottstonebridge.com');
        $email->getHeaders()->addTextHeader('X-Delivery-Type', 'reading');
        $email->getHeaders()->addTextHeader('X-Delivery-Id', (string) $delivery->id);
        $symfonySent = new SymfonySentMessage($email, new Envelope(new Address('scott@scottstonebridge.com'), [new Address('buyer@example.com')]));
        $symfonySent->setMessageId('1760000000');
        $symfonySent->appendDebug($this->smtpDialog('250 2.0.0 OK  1760000000 abc.12 - gsmtp'));

        event(new MessageSent(new SentMessage($symfonySent)));

        $this->assertSame('rfc-header-id@scottstonebridge.com', $delivery->refresh()->message_id);
    }

    public function test_a_failure_while_recording_proof_is_reported_but_never_breaks_the_send(): void
    {
        Exceptions::fake();
        $delivery = $this->readingDelivery();
        EmailReadingDelivery::saving(fn () => throw new RuntimeException('deadlock'));

        $this->dispatchSent($delivery->id, 'reading', $this->smtpDialog('250 2.0.0 OK  1 a.1 - gsmtp'));

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'deadlock');
    }

    public function test_a_resend_of_a_delivery_sent_before_dispatched_at_existed_archives_its_sent_at(): void
    {
        $delivery = $this->readingDelivery();
        $delivery->update([
            'message_id' => 'legacy@scottstonebridge.com',
            'smtp_response' => '250 OK legacy',
            'sent_at' => '2026-09-20 08:15:00',
            'dispatched_at' => null,
        ]);

        $this->dispatchSent($delivery->id, 'reading', $this->smtpDialog('250 2.0.0 OK  1 a.1 - gsmtp'), 'new@scottstonebridge.com');

        $entry = $delivery->refresh()->send_history[0];
        $this->assertSame('legacy@scottstonebridge.com', $entry['message_id']);
        $this->assertSame('2026-09-20 08:15:00', Carbon::parse($entry['dispatched_at'])->format('Y-m-d H:i:s'));
    }

    public function test_archived_dispatch_times_carry_a_utc_offset(): void
    {
        $delivery = $this->readingDelivery();
        $delivery->update(['message_id' => 'first@x', 'smtp_response' => '250 OK', 'dispatched_at' => '2026-07-01 12:00:00']);

        $this->dispatchSent($delivery->id, 'reading', $this->smtpDialog('250 2.0.0 OK  1 a.1 - gsmtp'), 'second@x');

        $stored = $delivery->refresh()->send_history[0]['dispatched_at'];
        $this->assertMatchesRegularExpression('/^2026-07-01T\d{2}:00:00[+-]\d{2}:\d{2}$/', $stored);
    }
}
