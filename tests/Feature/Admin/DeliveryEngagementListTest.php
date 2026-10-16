<?php

namespace Tests\Feature\Admin;

use App\Models\CampaignDelivery;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliveryEngagementListTest extends TestCase
{
    use RefreshDatabase;

    private int $lineItem = 1000;

    /**
     * @return array<string,array{0:string}>
     */
    public static function deliveryKinds(): array
    {
        return ['reading' => ['reading'], 'campaign' => ['campaign']];
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function delivery(string $kind, string $email, array $overrides = []): Model
    {
        $base = [
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => ++$this->lineItem,
            'customer_email' => $email,
            'customer_name' => 'Jane Doe',
            'status' => 'sent',
        ];

        if ($kind === 'campaign') {
            return CampaignDelivery::create(array_merge($base, $overrides));
        }

        $product = EmailReadingProduct::first() ?? EmailReadingProduct::create([
            'shopify_product_id' => 111,
            'name' => 'Reading',
            'slug' => 'reading',
            'questions_schema' => [],
            'prompt_template' => 'x',
        ]);

        return EmailReadingDelivery::create(array_merge($base, ['email_reading_product_id' => $product->id, 'questions' => []], $overrides));
    }

    /**
     * Three deliveries: direct open, proxy open, unopened.
     */
    private function seedEngagement(string $kind): void
    {
        $opened = ['opened_at' => '2026-10-15 09:00:00', 'last_opened_at' => '2026-10-15 09:00:00', 'open_count' => 1];

        $this->delivery($kind, 'direct@example.com', $opened + ['opened_client_type' => 'direct']);
        $this->delivery($kind, 'proxy@example.com', $opened + ['opened_client_type' => 'proxy']);
        $this->delivery($kind, 'unopened@example.com');
    }

    private function indexUrl(string $kind, array $query = []): string
    {
        return $kind === 'campaign'
            ? route('admin.campaign_deliveries.index', $query)
            : route('admin.email_readings.index', $query);
    }

    private function index(string $kind, array $query = [])
    {
        return $this->actingAs(User::factory()->create())->get($this->indexUrl($kind, $query));
    }

    #[DataProvider('deliveryKinds')]
    public function test_rows_show_an_engagement_pill_beside_the_status(string $kind): void
    {
        $this->seedEngagement($kind);

        $this->index($kind)->assertOk()
            ->assertSee('Proxy Opened')
            ->assertSee('Unopened')
            ->assertSee('direct@example.com');

        $html = $this->index($kind)->getContent();
        $this->assertSame(1, substr_count($html, 'data-engagement="direct"'));
        $this->assertSame(1, substr_count($html, 'data-engagement="proxy"'));
        $this->assertSame(1, substr_count($html, 'data-engagement="unopened"'));
    }

    #[DataProvider('deliveryKinds')]
    public function test_no_engagement_pill_is_shown_for_deliveries_that_were_not_sent(string $kind): void
    {
        $this->delivery($kind, 'pending@example.com', ['status' => 'pending']);

        $this->index($kind)->assertOk()->assertDontSee('data-engagement', false);
    }

    #[DataProvider('deliveryKinds')]
    public function test_the_filter_offers_every_engagement_option(string $kind): void
    {
        $this->index($kind)->assertOk()
            ->assertSee('name="engagement"', false)
            ->assertSee('All Engagement')
            ->assertSee('Opened (Any)')
            ->assertSee('Opened (Direct)')
            ->assertSee('Opened (Proxy)')
            ->assertSee('Unopened');
    }

    #[DataProvider('deliveryKinds')]
    public function test_filtering_by_engagement_narrows_the_list(string $kind): void
    {
        $this->seedEngagement($kind);

        $cases = [
            'opened' => ['direct@example.com', 'proxy@example.com'],
            'direct' => ['direct@example.com'],
            'proxy' => ['proxy@example.com'],
            'unopened' => ['unopened@example.com'],
        ];
        $all = ['direct@example.com', 'proxy@example.com', 'unopened@example.com'];

        foreach ($cases as $engagement => $expected) {
            $response = $this->index($kind, ['engagement' => $engagement])->assertOk();

            foreach ($expected as $email) {
                $response->assertSee($email);
            }
            foreach (array_diff($all, $expected) as $email) {
                $response->assertDontSee($email);
            }
        }
    }

    #[DataProvider('deliveryKinds')]
    public function test_an_unknown_engagement_value_is_ignored(string $kind): void
    {
        $this->seedEngagement($kind);

        $this->index($kind, ['engagement' => 'bogus'])->assertOk()
            ->assertSee('direct@example.com')
            ->assertSee('proxy@example.com')
            ->assertSee('unopened@example.com');
    }

    #[DataProvider('deliveryKinds')]
    public function test_the_csv_export_honours_the_engagement_filter(string $kind): void
    {
        $this->seedEngagement($kind);
        $url = $kind === 'campaign'
            ? route('admin.campaign_deliveries.export', ['engagement' => 'unopened'])
            : route('admin.email_readings.export', ['engagement' => 'unopened']);

        $csv = $this->actingAs(User::factory()->create())->get($url)->streamedContent();

        $this->assertStringContainsString('unopened@example.com', $csv);
        $this->assertStringNotContainsString('direct@example.com', $csv);
        $this->assertStringNotContainsString('proxy@example.com', $csv);
    }

    #[DataProvider('deliveryKinds')]
    public function test_the_unopened_filter_only_lists_sent_deliveries(string $kind): void
    {
        $this->delivery($kind, 'sent-unopened@example.com');
        $this->delivery($kind, 'pending@example.com', ['status' => 'pending']);
        $this->delivery($kind, 'failed@example.com', ['status' => 'failed']);

        $this->index($kind, ['engagement' => 'unopened'])->assertOk()
            ->assertSee('sent-unopened@example.com')
            ->assertDontSee('pending@example.com')
            ->assertDontSee('failed@example.com');
    }

    #[DataProvider('deliveryKinds')]
    public function test_the_csv_export_includes_engagement_and_delivery_proof_columns(string $kind): void
    {
        $this->delivery($kind, 'proxy@example.com', [
            'opened_at' => '2026-10-15 09:00:00',
            'last_opened_at' => '2026-10-15 09:00:00',
            'open_count' => 1,
            'opened_client_type' => 'proxy',
            'message_id' => 'abc123@scottstonebridge.com',
        ]);
        $this->delivery($kind, 'unopened@example.com');
        $url = $kind === 'campaign' ? route('admin.campaign_deliveries.export') : route('admin.email_readings.export');

        $csv = $this->actingAs(User::factory()->create())->get($url)->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));
        $header = array_shift($rows);
        $byEmail = [];
        foreach ($rows as $row) {
            $byEmail[$row[array_search('Customer Email', $header)]] = array_combine($header, $row);
        }

        $this->assertSame('Opened (Proxy)', $byEmail['proxy@example.com']['Engagement']);
        $this->assertSame('2026-10-15 09:00:00', $byEmail['proxy@example.com']['Opened At']);
        $this->assertSame('abc123@scottstonebridge.com', $byEmail['proxy@example.com']['Message-ID']);
        $this->assertSame('Unopened', $byEmail['unopened@example.com']['Engagement']);
        $this->assertSame('', $byEmail['unopened@example.com']['Opened At']);
    }
}
