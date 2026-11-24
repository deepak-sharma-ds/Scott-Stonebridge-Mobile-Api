<?php

namespace Tests\Feature\Webhook;

use App\Jobs\EmailReading\ProcessEmailReadingOrderJob;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The paid-order webhook stores the Shopify customer id on each Delivery so
 * Customer History can match a returning customer by id as well as email.
 */
class ReadingOrderCustomerIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ProcessEmailReadingOrderJob::class]);

        foreach ([111, 222] as $productId) {
            EmailReadingProduct::create([
                'shopify_product_id' => $productId,
                'name' => 'Reading '.$productId,
                'slug' => 'reading-'.$productId,
                'questions_schema' => [],
                'prompt_template' => 'Reading for {{ $customer_name }}',
                'email_subject' => 'Your reading',
                'is_active' => true,
                'is_automation_enabled' => true,
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $customer
     * @param  list<int>  $productIds
     * @return array<string,mixed>
     */
    private function order(array $customer, array $productIds = [111], string $email = 'buyer@example.com'): array
    {
        $lines = [];
        foreach ($productIds as $index => $productId) {
            $lines[] = ['id' => 500 + $index, 'product_id' => $productId, 'properties' => []];
        }

        $order = [
            'id' => 9001,
            'email' => $email,
            'line_items' => $lines,
            'shipping_lines' => [],
        ];

        if ($customer !== []) {
            $order['customer'] = $customer;
        }

        return $order;
    }

    public function test_the_customer_id_is_stored_on_the_delivery(): void
    {
        $this->postJson(route('webhook.shopify.reading'), $this->order(['id' => 7788, 'first_name' => 'Jane', 'last_name' => 'Doe']))
            ->assertOk();

        $delivery = EmailReadingDelivery::firstOrFail();
        $this->assertSame(7788, $delivery->shopify_customer_id);
        $this->assertSame('Jane Doe', $delivery->customer_name);
    }

    public function test_a_guest_checkout_still_creates_the_delivery_with_no_customer_id(): void
    {
        $this->postJson(route('webhook.shopify.reading'), $this->order([]))->assertOk();

        $delivery = EmailReadingDelivery::firstOrFail();
        $this->assertNull($delivery->shopify_customer_id);
        $this->assertSame(EmailReadingDelivery::STATUS_PENDING, $delivery->status);
        Queue::assertPushed(ProcessEmailReadingOrderJob::class);
    }

    public function test_a_customer_object_without_an_id_stores_null(): void
    {
        $this->postJson(route('webhook.shopify.reading'), $this->order(['first_name' => 'Jane']))->assertOk();

        $this->assertNull(EmailReadingDelivery::firstOrFail()->shopify_customer_id);
    }

    public function test_a_graphql_style_customer_id_is_reduced_to_its_number(): void
    {
        $this->postJson(route('webhook.shopify.reading'), $this->order(['id' => 'gid://shopify/Customer/4455']))->assertOk();

        $this->assertSame(4455, EmailReadingDelivery::firstOrFail()->shopify_customer_id);
    }

    public function test_an_unusable_customer_id_stores_null(): void
    {
        $this->postJson(route('webhook.shopify.reading'), $this->order(['id' => 'not-an-id']))->assertOk();

        $this->assertNull(EmailReadingDelivery::firstOrFail()->shopify_customer_id);
    }

    public function test_every_line_item_of_one_order_gets_the_same_customer_id(): void
    {
        $this->postJson(route('webhook.shopify.reading'), $this->order(['id' => 7788], [111, 222]))->assertOk();

        $this->assertSame(2, EmailReadingDelivery::count());
        $this->assertSame([7788], EmailReadingDelivery::pluck('shopify_customer_id')->unique()->all());
    }

    public function test_the_email_is_still_stored_exactly_as_received(): void
    {
        $this->postJson(route('webhook.shopify.reading'), $this->order(['id' => 7788], [111], 'Buyer.Name@Example.COM'))
            ->assertOk();

        $this->assertSame('Buyer.Name@Example.COM', EmailReadingDelivery::firstOrFail()->customer_email);
    }

    public function test_a_replayed_webhook_neither_duplicates_nor_alters_the_delivery(): void
    {
        $this->postJson(route('webhook.shopify.reading'), $this->order(['id' => 7788]), ['X-Shopify-Webhook-Id' => 'wh-1'])->assertOk();
        $this->postJson(route('webhook.shopify.reading'), $this->order(['id' => 9999]), ['X-Shopify-Webhook-Id' => 'wh-2'])->assertOk();

        $this->assertSame(1, EmailReadingDelivery::count());
        $this->assertSame(7788, EmailReadingDelivery::firstOrFail()->shopify_customer_id);
    }

    public function test_a_later_orders_updated_event_never_clears_the_customer_id(): void
    {
        $this->postJson(route('webhook.shopify.reading'), $this->order(['id' => 7788]))->assertOk();

        $upgrade = [['title' => 'SAME DAY GUARANTEE - Via Email', 'is_removed' => false]];
        $updated = $this->order([]);
        $updated['shipping_lines'] = $upgrade;
        $this->postJson(route('webhook.shopify.reading.updated'), $updated)->assertOk();

        $delivery = EmailReadingDelivery::firstOrFail();
        $this->assertNotNull($delivery->expedited_at);
        $this->assertSame(7788, $delivery->shopify_customer_id);
    }
}
