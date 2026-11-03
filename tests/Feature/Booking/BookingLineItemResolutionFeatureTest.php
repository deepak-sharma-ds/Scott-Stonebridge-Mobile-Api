<?php

namespace Tests\Feature\Booking;

use App\Services\Booking\BookingLineItemResolver;
use Tests\TestCase;

class BookingLineItemResolutionFeatureTest extends TestCase
{
    private BookingLineItemResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(BookingLineItemResolver::class);
    }

    public function test_resolves_booking_from_complex_multi_product_order(): void
    {
        config([
            'booking.product_ids' => [78910],
            'booking.product_handles' => ['psychic-medium-video-call'],
            'booking.product_skus' => ['CALL-30M'],
        ]);

        $orderPayload = [
            'id' => 123456789,
            'email' => 'client@stonebridge.com',
            'contact_email' => 'client@stonebridge.com',
            'customer' => [
                'first_name' => 'John',
                'last_name' => 'Smith',
                'email' => 'client@stonebridge.com',
            ],
            'billing_address' => [
                'name' => 'John Smith',
                'phone' => '+447000111222',
            ],
            'line_items' => [
                // Item 1: Physical Book (no properties)
                [
                    'id' => 1001,
                    'product_id' => 11111,
                    'title' => 'Spirit World Memoir',
                    'sku' => 'BOOK-HARDCOVER',
                    'properties' => [],
                ],
                // Item 2: Psychic Reading (custom reading properties)
                [
                    'id' => 1002,
                    'product_id' => 22222,
                    'title' => 'Email Tarot Reading',
                    'sku' => 'READING-3CARD',
                    'properties' => [
                        ['name' => 'question', 'value' => 'What does 2027 hold for me?'],
                        ['name' => 'date_of_birth', 'value' => '1985-04-12'],
                        ['name' => 'availability_date', 'value' => '2020-01-01'], // decoy on reading item
                    ],
                ],
                // Item 3: Appointment Booking (authentic targeted product)
                [
                    'id' => 1003,
                    'product_id' => 78910,
                    'title' => '1-on-1 Video Consultation',
                    'handle' => 'psychic-medium-video-call',
                    'sku' => 'CALL-30M',
                    'properties' => [
                        ['name' => 'availability_date', 'value' => '2026-11-20'],
                        ['name' => 'time_slot_id', 'value' => '88'],
                        ['name' => 'name', 'value' => 'Johnny Smith'], // explicit customer name override
                        ['name' => 'phone', 'value' => '+447999123456'],
                    ],
                ],
            ],
        ];

        $resolved = $this->resolver->resolve($orderPayload);

        $this->assertNotNull($resolved);
        $this->assertSame(123456789, $resolved['order_id']);
        $this->assertSame(1003, $resolved['line_item_id']);
        $this->assertSame(78910, $resolved['product_id']);
        $this->assertSame('Johnny Smith', $resolved['name']);
        $this->assertSame('client@stonebridge.com', $resolved['email']);
        $this->assertSame('+447999123456', $resolved['phone']);
        $this->assertSame('2026-11-20', $resolved['availability_date']);
        $this->assertSame(88, $resolved['time_slot_id']);

        // Assert that properties from Item 2 (Psychic Reading) were strictly kept isolated
        $this->assertArrayNotHasKey('question', $resolved['properties']);
        $this->assertArrayNotHasKey('date_of_birth', $resolved['properties']);
    }

    public function test_ignores_order_when_booking_item_has_unconfigured_product(): void
    {
        config([
            'booking.product_ids' => [99999], // Not present in order
            'booking.product_handles' => ['some-other-handle'],
            'booking.product_skus' => ['OTHER-SKU'],
        ]);

        $orderPayload = [
            'id' => 123456790,
            'line_items' => [
                [
                    'id' => 2001,
                    'product_id' => 12345,
                    'handle' => 'unmatched-product',
                    'sku' => 'UNMATCHED',
                    'properties' => [
                        ['name' => 'availability_date', 'value' => '2026-11-20'],
                        ['name' => 'time_slot_id', 'value' => '88'],
                    ],
                ],
            ],
        ];

        $resolved = $this->resolver->resolve($orderPayload);
        $this->assertNull($resolved);
    }
}
