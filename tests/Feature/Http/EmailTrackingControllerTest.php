<?php

namespace Tests\Feature\Http;

use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\MarketingCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmailTrackingControllerTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY_AGENT = 'Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)';

    private const DIRECT_AGENT = 'Microsoft Office/16.0 (Windows NT 10.0; Microsoft Outlook 16.0.14326; Pro)';

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
            'questions' => [],
            'status' => EmailReadingDelivery::STATUS_SENT,
        ]);
    }

    private function campaignDelivery(): CampaignDelivery
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'spring', 'name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
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
            'status' => CampaignDelivery::STATUS_SENT,
        ]);
    }

    private function open(string $token, string $userAgent = self::DIRECT_AGENT, string $ip = '81.2.69.142')
    {
        return $this->withHeaders(['User-Agent' => $userAgent])
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get(route('email.track', $token));
    }

    public function test_it_serves_a_43_byte_transparent_gif_that_must_not_be_cached(): void
    {
        $delivery = $this->readingDelivery();

        $response = $this->open($delivery->tracking_token);

        $response->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->assertSame(43, strlen($response->getContent()));
        $this->assertStringStartsWith('GIF89a', $response->getContent());
        $cacheControl = (string) $response->headers->get('Cache-Control');
        foreach (['no-cache', 'no-store', 'must-revalidate', 'max-age=0'] as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }
        $response->assertHeader('Pragma', 'no-cache')->assertHeader('Expires', '0');
    }

    public function test_it_does_not_start_a_session_for_the_pixel(): void
    {
        $delivery = $this->readingDelivery();

        $this->open($delivery->tracking_token)->assertCookieMissing(config('session.cookie'));
    }

    public function test_an_unknown_or_malformed_token_still_returns_the_image(): void
    {
        $this->open('00000000-0000-4000-8000-000000000000')->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->open('not-a-token')->assertOk()->assertHeader('Content-Type', 'image/gif');
    }

    public function test_the_first_open_of_a_reading_is_recorded(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $delivery = $this->readingDelivery();

        $this->open($delivery->tracking_token, self::DIRECT_AGENT, '81.2.69.142');

        $delivery->refresh();
        $this->assertSame('2026-10-14 12:00:00', $delivery->opened_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-14 12:00:00', $delivery->last_opened_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $delivery->open_count);
        $this->assertSame('direct', $delivery->opened_client_type);
        $this->assertSame('81.2.69.142', $delivery->opened_ip);
        $this->assertSame(self::DIRECT_AGENT, $delivery->opened_user_agent);
    }

    public function test_the_first_open_of_a_campaign_email_is_recorded(): void
    {
        $delivery = $this->campaignDelivery();

        $this->open($delivery->tracking_token, self::PROXY_AGENT, '66.249.84.1');

        $delivery->refresh();
        $this->assertSame(1, $delivery->open_count);
        $this->assertSame('proxy', $delivery->opened_client_type);
        $this->assertNotNull($delivery->opened_at);
    }

    public function test_a_later_open_keeps_the_first_open_time_and_bumps_the_count(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $delivery = $this->readingDelivery();
        $this->open($delivery->tracking_token);

        Carbon::setTestNow('2026-10-14 12:00:30');
        $this->open($delivery->tracking_token, self::PROXY_AGENT, '66.249.84.1');

        $delivery->refresh();
        $this->assertSame('2026-10-14 12:00:00', $delivery->opened_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-14 12:00:30', $delivery->last_opened_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, $delivery->open_count);
        $this->assertSame('proxy', $delivery->opened_client_type);
    }

    public function test_rapid_fetches_within_five_seconds_count_once_and_skip_the_database(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $delivery = $this->readingDelivery();
        $this->open($delivery->tracking_token);

        Carbon::setTestNow('2026-10-14 12:00:04');
        DB::enableQueryLog();
        $response = $this->open($delivery->tracking_token);

        $response->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->assertSame([], DB::getQueryLog());
        $this->assertSame(1, $delivery->refresh()->open_count);
    }

    public function test_a_fetch_after_the_five_second_window_counts_again(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $delivery = $this->campaignDelivery();
        $this->open($delivery->tracking_token);

        Carbon::setTestNow('2026-10-14 12:00:06');
        $this->open($delivery->tracking_token);

        $this->assertSame(2, $delivery->refresh()->open_count);
    }

    public function test_an_overlong_user_agent_is_stored_without_error(): void
    {
        $delivery = $this->readingDelivery();

        $this->open($delivery->tracking_token, str_repeat('A', 5000))->assertOk();

        $this->assertSame(1, $delivery->refresh()->open_count);
    }
}
