<?php

namespace Tests\Feature\Models;

use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\MarketingCampaign;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliveryTrackingColumnsTest extends TestCase
{
    use RefreshDatabase;

    private const TRACKING_COLUMNS = [
        'tracking_token', 'message_id', 'smtp_response', 'send_history',
        'dispatched_at', 'opened_at', 'last_opened_at', 'open_count',
        'opened_client_type', 'opened_ip', 'opened_user_agent',
    ];

    /**
     * @return array<string,array{0:class-string<Model>,1:string,2:string}>
     */
    public static function deliveryModels(): array
    {
        return [
            'reading' => [EmailReadingDelivery::class, 'email_reading_deliveries', 'email_reading_product_id'],
            'campaign' => [CampaignDelivery::class, 'campaign_deliveries', 'campaign_product_id'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rowAttributes(string $parentKey, int $lineItemId): array
    {
        return array_merge([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => $lineItemId,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'status' => 'pending',
        ], $parentKey === 'email_reading_product_id' ? ['questions' => []] : [], [$parentKey => $this->parentId($parentKey)]);
    }

    private function parentId(string $parentKey): int
    {
        if ($parentKey === 'email_reading_product_id') {
            return (EmailReadingProduct::first() ?? EmailReadingProduct::create([
                'shopify_product_id' => 111,
                'name' => 'Reading',
                'slug' => 'reading',
                'questions_schema' => [],
                'prompt_template' => 'x',
            ]))->id;
        }

        $campaign = MarketingCampaign::first() ?? MarketingCampaign::create([
            'campaign_key' => 'spring',
            'name' => 'Spring',
            'status' => MarketingCampaign::STATUS_ACTIVE,
        ]);

        return (CampaignProduct::first() ?? CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'shopify_variant_id' => 5001,
            'product_title' => 'Reading',
        ]))->id;
    }

    #[DataProvider('deliveryModels')]
    public function test_both_delivery_tables_have_the_tracking_columns(string $model, string $table): void
    {
        foreach (self::TRACKING_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn($table, $column), "{$table} is missing {$column}");
        }
    }

    #[DataProvider('deliveryModels')]
    public function test_a_new_delivery_gets_a_unique_uuid_tracking_token_and_zero_opens(string $model, string $table, string $parentKey): void
    {
        $first = $model::create($this->rowAttributes($parentKey, 1))->refresh();
        $second = $model::create($this->rowAttributes($parentKey, 2))->refresh();

        $this->assertTrue(Str::isUuid($first->tracking_token));
        $this->assertNotSame($first->tracking_token, $second->tracking_token);
        $this->assertSame(0, $first->open_count);
        $this->assertNull($first->opened_at);
    }

    #[DataProvider('deliveryModels')]
    public function test_an_explicit_tracking_token_is_preserved(string $model, string $table, string $parentKey): void
    {
        $token = (string) Str::uuid();

        $delivery = $model::create($this->rowAttributes($parentKey, 1) + ['tracking_token' => $token]);

        $this->assertSame($token, $delivery->refresh()->tracking_token);
    }

    #[DataProvider('deliveryModels')]
    public function test_tracking_attributes_are_mass_assignable_and_cast(string $model, string $table, string $parentKey): void
    {
        $history = [['dispatched_at' => '2026-10-01 10:00:00', 'message_id' => '<a@b>', 'smtp_response' => '250 2.0.0 OK']];

        $delivery = $model::create($this->rowAttributes($parentKey, 1) + [
            'message_id' => '<abc@scottstonebridge.com>',
            'smtp_response' => '250 2.0.0 OK',
            'send_history' => $history,
            'opened_at' => '2026-10-02 09:00:00',
            'last_opened_at' => '2026-10-03 09:00:00',
            'open_count' => '3',
            'opened_client_type' => 'direct',
            'opened_ip' => '203.0.113.9',
            'opened_user_agent' => 'Mozilla/5.0',
        ])->refresh();

        $this->assertSame($history, $delivery->send_history);
        $this->assertSame('2026-10-02 09:00:00', $delivery->opened_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-03 09:00:00', $delivery->last_opened_at->format('Y-m-d H:i:s'));
        $this->assertSame(3, $delivery->open_count);
        $this->assertSame('direct', $delivery->opened_client_type);
    }

    #[DataProvider('deliveryModels')]
    public function test_the_migration_backfills_a_unique_token_for_every_existing_row_beyond_one_batch(string $model, string $table, string $parentKey): void
    {
        $migration = require database_path('migrations/2026_10_14_100000_add_tracking_and_audit_columns_to_delivery_tables.php');
        $migration->down();

        $total = 2350;
        foreach (array_chunk(range(1, $total), 100) as $lineItemIds) {
            DB::table($table)->insert(array_map(fn (int $lineItemId) => array_map(fn ($value) => is_array($value) ? json_encode($value) : $value, $this->rowAttributes($parentKey, $lineItemId)) + [
                'created_at' => now(),
                'updated_at' => now(),
            ], $lineItemIds));
        }

        $migration->up();

        $tokens = DB::table($table)->pluck('tracking_token');
        $this->assertCount($total, $tokens);
        $this->assertCount($total, $tokens->unique());
        $tokens->each(fn ($token) => $this->assertTrue(Str::isUuid($token)));
        $this->assertSame(0, (int) DB::table($table)->min('open_count'));
    }
}
