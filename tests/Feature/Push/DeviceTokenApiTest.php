<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Http\Middleware\ShopifyAuthMiddleware;
use App\Models\DeviceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * POST/DELETE /api/v1/push/device-tokens and PUT /api/v1/push/preferences.
 *
 * shopify.auth is bypassed (exercised elsewhere); customer identity is passed
 * via the shopify_customer_data body key the middleware would otherwise merge.
 */
class DeviceTokenApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, ShopifyAuthMiddleware::class]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function customer(int $id = 12345, string $email = 'buyer@example.com'): array
    {
        return ['id' => $id, 'email' => $email];
    }

    public function test_register_creates_device_token(): void
    {
        $response = $this->postJson('/api/v1/push/device-tokens', [
            'shopify_customer_data' => $this->customer(),
            'fcm_token' => 'token-abc',
            'platform' => 'ios',
            'device_id' => 'device-1',
            'app_version' => '1.2.0',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('device_tokens', [
            'fcm_token' => 'token-abc',
            'shopify_customer_id' => 12345,
            'customer_email' => 'buyer@example.com',
            'platform' => 'ios',
            'revoked_at' => null,
        ]);
    }

    public function test_register_upserts_and_reassigns_existing_token(): void
    {
        DeviceToken::factory()->revoked()->create([
            'fcm_token' => 'shared-token',
            'shopify_customer_id' => 111,
            'customer_email' => 'old@example.com',
        ]);

        $this->postJson('/api/v1/push/device-tokens', [
            'shopify_customer_data' => $this->customer(222, 'new@example.com'),
            'fcm_token' => 'shared-token',
            'platform' => 'android',
        ])->assertStatus(201);

        $this->assertSame(1, DeviceToken::where('fcm_token', 'shared-token')->count());
        $this->assertDatabaseHas('device_tokens', [
            'fcm_token' => 'shared-token',
            'shopify_customer_id' => 222,
            'customer_email' => 'new@example.com',
            'revoked_at' => null,
        ]);
    }

    public function test_register_normalizes_gid_customer_id(): void
    {
        $this->postJson('/api/v1/push/device-tokens', [
            'shopify_customer_data' => ['id' => 'gid://shopify/Customer/987', 'email' => 'gid@example.com'],
            'fcm_token' => 'gid-token',
            'platform' => 'ios',
        ])->assertStatus(201);

        $this->assertDatabaseHas('device_tokens', [
            'fcm_token' => 'gid-token',
            'shopify_customer_id' => 987,
        ]);
    }

    public function test_register_validates_platform(): void
    {
        $this->postJson('/api/v1/push/device-tokens', [
            'shopify_customer_data' => $this->customer(),
            'fcm_token' => 'token-xyz',
            'platform' => 'windows',
        ])->assertStatus(422);
    }

    public function test_unregister_deletes_token(): void
    {
        DeviceToken::factory()->create(['fcm_token' => 'to-remove']);

        $this->deleteJson('/api/v1/push/device-tokens', [
            'shopify_customer_data' => $this->customer(),
            'fcm_token' => 'to-remove',
        ])->assertStatus(200);

        $this->assertDatabaseMissing('device_tokens', ['fcm_token' => 'to-remove']);
    }

    public function test_preferences_toggle_all_customer_devices(): void
    {
        DeviceToken::factory()->count(2)->create(['shopify_customer_id' => 555]);

        $this->putJson('/api/v1/push/preferences', [
            'shopify_customer_data' => $this->customer(555, 'buyer@example.com'),
            'enabled' => false,
        ])->assertStatus(200);

        $this->assertSame(0, DeviceToken::where('shopify_customer_id', 555)->where('push_enabled', true)->count());
    }
}
