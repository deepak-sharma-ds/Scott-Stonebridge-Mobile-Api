<?php

namespace Tests\Feature\Booking;

use App\Jobs\Shopify\ProcessShopifyWebhookEventJob;
use App\Models\AvailabilityDate;
use App\Models\ScheduledMeeting;
use App\Models\ShopifyWebhookEvent;
use App\Models\TimeSlot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyBookingWebhookFeatureTest extends TestCase
{
    use RefreshDatabase;

    private AvailabilityDate $date;

    private TimeSlot $slot1;

    private TimeSlot $slot2;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'booking.product_ids' => [1001],
            'booking.timezone' => 'Europe/London',
            'booking.hmac_enabled' => false,
        ]);

        $user = User::factory()->create();

        $this->date = AvailabilityDate::create([
            'user_id' => $user->id,
            'date' => '2026-11-20',
        ]);

        $this->slot1 = TimeSlot::create([
            'availability_date_id' => $this->date->id,
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
        ]);

        $this->slot2 = TimeSlot::create([
            'availability_date_id' => $this->date->id,
            'start_time' => '10:30:00',
            'end_time' => '11:00:00',
        ]);
    }

    public function test_order_paid_webhook_stores_event_and_processes_booking(): void
    {
        $payload = [
            'id' => 888101,
            'email' => 'client@example.com',
            'customer' => [
                'first_name' => 'Alice',
                'last_name' => 'Walker',
                'email' => 'client@example.com',
            ],
            'line_items' => [
                [
                    'id' => 99901,
                    'product_id' => 1001,
                    'name' => 'Consultation with Scott',
                    'properties' => [
                        ['name' => 'availability_date', 'value' => '2026-11-20'],
                        ['name' => 'time_slot_id', 'value' => (string) $this->slot1->id],
                        ['name' => 'phone', 'value' => '+447111222333'],
                    ],
                ],
            ],
        ];

        $response = $this->postJson(route('webhook.shopify.booking.paid'), $payload, [
            'X-Shopify-Webhook-Id' => 'webhook-uuid-001',
            'X-Shopify-Topic' => 'orders/paid',
        ]);

        $response->assertStatus(200);

        // Assert webhook was recorded in inbox
        $this->assertDatabaseHas('shopify_webhook_events', [
            'endpoint' => 'booking.paid',
            'shopify_webhook_id' => 'webhook-uuid-001',
            'shopify_order_id' => 888101,
        ]);

        // Process queued job synchronously for testing
        $event = ShopifyWebhookEvent::where('shopify_webhook_id', 'webhook-uuid-001')->firstOrFail();
        ProcessShopifyWebhookEventJob::dispatchSync($event->id);

        // Assert ScheduledMeeting created with confirmed status
        $this->assertDatabaseHas('scheduled_meetings', [
            'order_id' => 888101,
            'name' => 'Alice Walker',
            'email' => 'client@example.com',
            'phone' => '+447111222333',
            'time_slot_id' => $this->slot1->id,
            'status' => 'confirmed',
        ]);

        $meeting = ScheduledMeeting::where('order_id', 888101)->firstOrFail();
        $this->assertSame('2026-11-20 10:00:00', Carbon::parse($meeting->datetime)->format('Y-m-d H:i:s'));
    }

    public function test_duplicate_webhook_is_idempotent_and_does_not_create_duplicate_meetings(): void
    {
        $payload = [
            'id' => 888102,
            'email' => 'dup@example.com',
            'line_items' => [
                [
                    'id' => 99902,
                    'product_id' => 1001,
                    'properties' => [
                        ['name' => 'availability_date', 'value' => '2026-11-20'],
                        ['name' => 'time_slot_id', 'value' => (string) $this->slot1->id],
                    ],
                ],
            ],
        ];

        // Send first time
        $this->postJson(route('webhook.shopify.booking.paid'), $payload, [
            'X-Shopify-Webhook-Id' => 'webhook-uuid-002',
            'X-Shopify-Topic' => 'orders/paid',
        ])->assertStatus(200);

        // Process first event
        $event1 = ShopifyWebhookEvent::where('shopify_webhook_id', 'webhook-uuid-002')->firstOrFail();
        ProcessShopifyWebhookEventJob::dispatchSync($event1->id);

        $this->assertDatabaseCount('scheduled_meetings', 1);

        // Send second time (same webhook ID)
        $this->postJson(route('webhook.shopify.booking.paid'), $payload, [
            'X-Shopify-Webhook-Id' => 'webhook-uuid-002',
            'X-Shopify-Topic' => 'orders/paid',
        ])->assertStatus(200);

        // Verify still exactly 1 meeting exists
        $this->assertDatabaseCount('scheduled_meetings', 1);
    }

    public function test_slot_collision_marks_trailing_order_as_needs_reschedule(): void
    {
        // Existing confirmed booking on slot 1
        ScheduledMeeting::create([
            'order_id' => 777001,
            'availability_date_id' => $this->date->id,
            'time_slot_id' => $this->slot1->id,
            'name' => 'First Customer',
            'email' => 'first@example.com',
            'phone' => '+447123456789',
            'datetime' => '2026-11-20 10:00:00',
            'meeting_link' => 'https://meet.google.com/abc',
            'event_id' => 'first_event_123',
            'status' => 'confirmed',
        ]);

        $payload = [
            'id' => 888103,
            'email' => 'second@example.com',
            'customer' => ['first_name' => 'Second', 'last_name' => 'Customer'],
            'line_items' => [
                [
                    'id' => 99903,
                    'product_id' => 1001,
                    'properties' => [
                        ['name' => 'availability_date', 'value' => '2026-11-20'],
                        ['name' => 'time_slot_id', 'value' => (string) $this->slot1->id], // Same occupied slot!
                    ],
                ],
            ],
        ];

        $this->postJson(route('webhook.shopify.booking.paid'), $payload, [
            'X-Shopify-Webhook-Id' => 'webhook-uuid-003',
            'X-Shopify-Topic' => 'orders/paid',
        ])->assertStatus(200);

        $event = ShopifyWebhookEvent::where('shopify_webhook_id', 'webhook-uuid-003')->firstOrFail();
        ProcessShopifyWebhookEventJob::dispatchSync($event->id);

        // The second customer's booking should be created with status 'needs_reschedule' without stealing the slot
        $this->assertDatabaseHas('scheduled_meetings', [
            'order_id' => 888103,
            'email' => 'second@example.com',
            'status' => 'needs_reschedule',
        ]);

        // First customer's booking is unchanged
        $firstMeeting = ScheduledMeeting::where('order_id', 777001)->firstOrFail();
        $this->assertSame('confirmed', $firstMeeting->status);
        $this->assertSame($this->slot1->id, $firstMeeting->time_slot_id);
    }

    public function test_order_cancelled_webhook_soft_deletes_booking_and_frees_slot(): void
    {
        $meeting = ScheduledMeeting::create([
            'order_id' => 888104,
            'availability_date_id' => $this->date->id,
            'time_slot_id' => $this->slot1->id,
            'name' => 'Cancelled Client',
            'email' => 'cancelled@example.com',
            'phone' => '+447123456789',
            'datetime' => '2026-11-20 10:00:00',
            'meeting_link' => 'https://meet.google.com/xyz',
            'event_id' => 'google_event_123',
            'status' => 'confirmed',
        ]);

        $payload = [
            'id' => 888104,
            'email' => 'cancelled@example.com',
        ];

        $this->postJson(route('webhook.shopify.booking.cancelled'), $payload, [
            'X-Shopify-Webhook-Id' => 'webhook-uuid-004',
            'X-Shopify-Topic' => 'orders/cancelled',
        ])->assertStatus(200);

        $event = ShopifyWebhookEvent::where('shopify_webhook_id', 'webhook-uuid-004')->firstOrFail();
        ProcessShopifyWebhookEventJob::dispatchSync($event->id);

        // Status transitioned to cancelled and time slot freed
        $meeting->refresh();
        $this->assertSame('cancelled', $meeting->status);
        $this->assertNull($meeting->time_slot_id);

        // Row was preserved (not hard deleted)
        $this->assertDatabaseHas('scheduled_meetings', [
            'order_id' => 888104,
            'status' => 'cancelled',
        ]);
    }

    public function test_multi_slot_duration_blocks_subsequent_adjacent_slots(): void
    {
        $payload = [
            'id' => 888105,
            'email' => 'extended@example.com',
            'customer' => [
                'first_name' => 'Extended',
                'last_name' => 'Client',
                'email' => 'extended@example.com',
            ],
            'line_items' => [
                [
                    'id' => 99905,
                    'product_id' => 1001,
                    'properties' => [
                        ['name' => 'availability_date', 'value' => '2026-11-20'],
                        ['name' => 'time_slot_id', 'value' => (string) $this->slot1->id],
                        ['name' => 'duration', 'value' => '60'], // 60 minutes = 2 slots (10:00 - 11:00)
                    ],
                ],
            ],
        ];

        $this->postJson(route('webhook.shopify.booking.paid'), $payload, [
            'X-Shopify-Webhook-Id' => 'webhook-uuid-005',
            'X-Shopify-Topic' => 'orders/paid',
        ])->assertStatus(200);

        $event = ShopifyWebhookEvent::where('shopify_webhook_id', 'webhook-uuid-005')->firstOrFail();
        ProcessShopifyWebhookEventJob::dispatchSync($event->id);

        // Slot 1 holds the primary confirmed meeting
        $this->assertDatabaseHas('scheduled_meetings', [
            'order_id' => 888105,
            'time_slot_id' => $this->slot1->id,
            'status' => 'confirmed',
        ]);

        // Slot 2 holds the linked blocked_by_calendar record
        $this->assertDatabaseHas('scheduled_meetings', [
            'order_id' => 888105,
            'time_slot_id' => $this->slot2->id,
            'status' => 'blocked_by_calendar',
        ]);
    }

    public function test_webhook_verifies_hmac_signature_when_enabled(): void
    {
        config([
            'booking.hmac_enabled' => true,
            'booking.shopify_webhook_secret' => 'super_secret_test_key',
        ]);

        $payload = [
            'id' => 888106,
            'email' => 'hmac@example.com',
        ];

        $rawBody = json_encode($payload);
        $validHmac = base64_encode(hash_hmac('sha256', $rawBody, 'super_secret_test_key', true));

        // Invalid HMAC -> 401 Unauthorized
        $this->call('POST', route('webhook.shopify.booking.paid'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'webhook-uuid-006-bad',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => 'invalid_signature',
        ], $rawBody)->assertStatus(401);

        // Valid HMAC -> 200 OK
        $this->call('POST', route('webhook.shopify.booking.paid'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'webhook-uuid-006-good',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $validHmac,
        ], $rawBody)->assertStatus(200);
    }
}
