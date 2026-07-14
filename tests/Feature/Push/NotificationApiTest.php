<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Http\Middleware\ShopifyAuthMiddleware;
use App\Models\PushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * GET /api/v1/push/notifications and /api/v1/push/notifications/{id}.
 *
 * shopify.auth is bypassed (exercised elsewhere); customer identity is passed
 * via the shopify_customer_data query key the middleware would otherwise merge.
 */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, ShopifyAuthMiddleware::class]);
    }

    protected function asCustomer(string $email = 'buyer@example.com'): array
    {
        return ['shopify_customer_data' => ['id' => 12345, 'email' => $email]];
    }

    public function test_index_lists_only_callers_sent_notifications(): void
    {
        PushNotification::factory()->sent()->create(['recipient_email' => 'buyer@example.com', 'title' => 'Mine']);
        PushNotification::factory()->sent()->create(['recipient_email' => 'other@example.com', 'title' => 'Not mine']);
        PushNotification::factory()->create(['recipient_email' => 'buyer@example.com', 'status' => PushNotification::STATUS_PENDING, 'title' => 'Still pending']);

        $response = $this->getJson('/api/v1/push/notifications?'.http_build_query($this->asCustomer()));

        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertSame(['Mine'], $titles->all());
    }

    public function test_index_filters_by_read_status(): void
    {
        PushNotification::factory()->sent()->create(['recipient_email' => 'buyer@example.com', 'read_at' => now(), 'title' => 'Read one']);
        PushNotification::factory()->sent()->create(['recipient_email' => 'buyer@example.com', 'title' => 'Unread one']);

        $response = $this->getJson('/api/v1/push/notifications?status=unread&'.http_build_query($this->asCustomer()));

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertSame(['Unread one'], $titles->all());
        $this->assertSame(1, $response->json('meta.pagination.unread_count'));
    }

    public function test_show_marks_notification_read(): void
    {
        $notification = PushNotification::factory()->sent()->create(['recipient_email' => 'buyer@example.com']);

        $response = $this->getJson('/api/v1/push/notifications/'.$notification->id.'?'.http_build_query($this->asCustomer()));

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.is_read'));
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_show_hides_other_customers_notification(): void
    {
        $notification = PushNotification::factory()->sent()->create(['recipient_email' => 'other@example.com']);

        $this->getJson('/api/v1/push/notifications/'.$notification->id.'?'.http_build_query($this->asCustomer()))
            ->assertStatus(404);
    }
}
