<?php

namespace Tests\Feature;

use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\CampaignProductResponse;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\MarketingCampaign;
use App\Models\User;
use App\Services\CampaignEmail\CampaignDeliveryLifecycle;
use App\Services\EmailReading\EmailReadingDeliveryLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * End-to-end Delivery Proof and Open Tracking: real mailable sent through the
 * array transport -> MessageSent capture -> pixel fetch -> admin show/index,
 * for both Email Reading and Campaign Email deliveries.
 */
class EmailDeliveryAuditLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const GMAIL_PROXY = 'Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'email_reading.queue.connection' => 'sync',
            'email_reading.fulfillment.enabled' => false,
            'campaign_email.queue.connection' => 'sync',
            'campaign_email.fulfillment.enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function deliveryKinds(): array
    {
        return ['reading' => ['reading'], 'campaign' => ['campaign']];
    }

    private function delivery(string $kind): Model
    {
        if ($kind === 'reading') {
            $product = EmailReadingProduct::create([
                'shopify_product_id' => 111,
                'name' => 'Reading',
                'slug' => 'reading',
                'questions_schema' => [],
                'prompt_template' => 'x',
                'email_subject' => 'Your reading',
            ]);

            return EmailReadingDelivery::create([
                'shopify_order_id' => 9001,
                'shopify_line_item_id' => 501,
                'email_reading_product_id' => $product->id,
                'customer_email' => 'buyer@example.com',
                'customer_name' => 'Jane Doe',
                'questions' => [],
                'ai_response' => '<p>Your reading.</p>',
                'status' => EmailReadingDelivery::STATUS_GENERATED,
            ]);
        }

        $campaign = MarketingCampaign::create(['campaign_key' => 'spring', 'name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $product = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'shopify_variant_id' => 5001,
            'product_title' => 'Reading',
        ]);
        CampaignProductResponse::create([
            'campaign_product_id' => $product->id,
            'source' => CampaignProductResponse::SOURCE_MANUAL,
            'body' => '<p>Campaign copy.</p>',
        ]);

        return CampaignDelivery::create([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 501,
            'campaign_product_id' => $product->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'status' => CampaignDelivery::STATUS_PENDING,
            'scheduled_at' => now()->subMinute(),
        ]);
    }

    private function send(string $kind, Model $delivery, bool $resend = false): void
    {
        $lifecycle = $kind === 'reading'
            ? app(EmailReadingDeliveryLifecycle::class)
            : app(CampaignDeliveryLifecycle::class);

        $lifecycle->sendNow($delivery, $resend);
    }

    private function lastMessage(): Email
    {
        return app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    }

    private function openPixel(string $token, string $userAgent)
    {
        return $this->withHeaders(['User-Agent' => $userAgent])->get(route('email.track', $token));
    }

    private function showUrl(string $kind, Model $delivery): string
    {
        return $kind === 'reading'
            ? route('admin.email_readings.show', $delivery)
            : route('admin.campaign_deliveries.show', $delivery);
    }

    private function indexUrl(string $kind, array $query): string
    {
        return $kind === 'reading'
            ? route('admin.email_readings.index', $query)
            : route('admin.campaign_deliveries.index', $query);
    }

    #[DataProvider('deliveryKinds')]
    public function test_the_full_proof_and_tracking_lifecycle(string $kind): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $delivery = $this->delivery($kind);
        $admin = User::factory()->create();

        $this->send($kind, $delivery);

        $delivery->refresh();
        $message = $this->lastMessage();
        $this->assertSame('sent', $delivery->status);
        $this->assertSame($kind, $message->getHeaders()->get('X-Delivery-Type')->getBodyAsString());
        $this->assertSame((string) $delivery->id, $message->getHeaders()->get('X-Delivery-Id')->getBodyAsString());
        $this->assertStringContainsString(route('email.track', $delivery->tracking_token), $message->getHtmlBody());
        $this->assertNotEmpty($delivery->message_id);
        $this->assertStringContainsString('array', $delivery->smtp_response);
        $this->assertSame('2026-10-14 12:00:00', $delivery->dispatched_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $delivery->open_count);

        $this->actingAs($admin)->get($this->showUrl($kind, $delivery))->assertOk()->assertSee('Unopened');

        $this->openPixel($delivery->tracking_token, self::GMAIL_PROXY)->assertOk();
        $this->openPixel($delivery->tracking_token, self::GMAIL_PROXY)->assertOk();

        $delivery->refresh();
        $this->assertSame(1, $delivery->open_count, 'the rapid second fetch is debounced');
        $this->assertSame('proxy', $delivery->opened_client_type);

        $this->actingAs($admin)->get($this->showUrl($kind, $delivery))->assertOk()->assertSee('Opened (Proxy)');
        $this->actingAs($admin)->get($this->indexUrl($kind, ['engagement' => 'proxy']))->assertOk()->assertSee('buyer@example.com');
        $this->actingAs($admin)->get($this->indexUrl($kind, ['engagement' => 'unopened']))->assertOk()->assertDontSee('buyer@example.com');
    }

    #[DataProvider('deliveryKinds')]
    public function test_a_resend_keeps_the_token_and_archives_the_first_dispatch(string $kind): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $delivery = $this->delivery($kind);
        $this->send($kind, $delivery);
        $delivery->refresh();
        $token = $delivery->tracking_token;
        $firstMessageId = $delivery->message_id;
        $firstSmtp = $delivery->smtp_response;

        Carbon::setTestNow('2026-10-15 09:30:00');
        $this->send($kind, $delivery, resend: true);

        $delivery->refresh();
        $this->assertSame($token, $delivery->tracking_token);
        $this->assertStringContainsString($token, $this->lastMessage()->getHtmlBody());
        $this->assertNotSame($firstMessageId, $delivery->message_id);
        $this->assertSame('2026-10-15 09:30:00', $delivery->dispatched_at->format('Y-m-d H:i:s'));
        $this->assertCount(1, $delivery->send_history);
        $entry = $delivery->send_history[0];
        $this->assertSame($firstMessageId, $entry['message_id']);
        $this->assertSame($firstSmtp, $entry['smtp_response']);
        $this->assertSame('2026-10-14 12:00:00', Carbon::parse($entry['dispatched_at'])->format('Y-m-d H:i:s'));

        $this->actingAs(User::factory()->create())->get($this->showUrl($kind, $delivery))
            ->assertOk()
            ->assertSee('Send History')
            ->assertSee($firstMessageId);
    }

    #[DataProvider('deliveryKinds')]
    public function test_a_send_test_to_me_is_untracked_and_leaves_deliveries_untouched(string $kind): void
    {
        $delivery = $this->delivery($kind);
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        if ($kind === 'reading') {
            $url = route('admin.email-reading-products.send-test', $delivery->product);
        } else {
            $url = route('admin.marketing-campaigns.products.send-test', [$delivery->campaignProduct->marketingCampaign, $delivery->campaignProduct]);
        }

        $this->actingAs($admin)->postJson($url, ['body' => '<p>Body</p>'])->assertOk();

        $message = $this->lastMessage();
        $this->assertFalse($message->getHeaders()->has('X-Delivery-Type'));
        $this->assertStringNotContainsString('/track/email/', $message->getHtmlBody());
        $delivery->refresh();
        $this->assertNull($delivery->message_id);
        $this->assertNull($delivery->dispatched_at);
    }

    #[DataProvider('deliveryKinds')]
    public function test_fetches_further_apart_than_the_debounce_window_each_count(string $kind): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $delivery = $this->delivery($kind);

        $this->openPixel($delivery->tracking_token, 'Outlook');
        Carbon::setTestNow('2026-10-14 12:00:10');
        $this->openPixel($delivery->tracking_token, 'Outlook');

        $this->assertSame(2, $delivery->refresh()->open_count);
    }
}
