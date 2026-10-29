<?php

namespace Tests\Unit\Services\Booking;

use App\Services\Booking\BookingLineItemResolver;
use Tests\TestCase;

class BookingLineItemResolverTest extends TestCase
{
    private BookingLineItemResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new BookingLineItemResolver;
    }

    public function test_resolves_single_booking_line_item_with_isolated_properties(): void
    {
        config(['booking.product_ids' => [101]]);

        $order = [
            'id' => 9001,
            'email' => 'customer@example.com',
            'customer' => [
                'first_name' => 'Rhys',
                'last_name' => 'Hughes',
                'email' => 'customer@example.com',
                'phone' => '+447123456789',
            ],
            'line_items' => [
                [
                    'id' => 5001,
                    'product_id' => 101,
                    'name' => '1-on-1 Consultation',
                    'properties' => [
                        ['name' => 'availability_date', 'value' => '2026-10-27'],
                        ['name' => 'time_slot_id', 'value' => '45'],
                        ['name' => 'phone', 'value' => '+447999888777'],
                    ],
                ],
            ],
        ];

        $resolved = $this->resolver->resolve($order);

        $this->assertNotNull($resolved);
        $this->assertSame(9001, $resolved['order_id']);
        $this->assertSame(5001, $resolved['line_item_id']);
        $this->assertSame(101, $resolved['product_id']);
        $this->assertSame('Rhys Hughes', $resolved['name']);
        $this->assertSame('customer@example.com', $resolved['email']);
        $this->assertSame('+447999888777', $resolved['phone']);
        $this->assertSame('2026-10-27', $resolved['availability_date']);
        $this->assertSame(45, $resolved['time_slot_id']);
    }

    public function test_multi_item_order_isolates_properties_strictly_to_booking_line_item(): void
    {
        config(['booking.product_ids' => [202]]);

        $order = [
            'id' => 9002,
            'email' => 'jane@example.com',
            'customer' => ['first_name' => 'Jane', 'last_name' => 'Doe'],
            'line_items' => [
                [
                    'id' => 4001,
                    'product_id' => 999, // Psychic Reading or Book
                    'name' => 'Email Psychic Reading',
                    'properties' => [
                        ['name' => 'question_1', 'value' => 'What is my future?'],
                        ['name' => 'availability_date', 'value' => '2020-01-01'], // Corrupted or decoy property on another item
                        ['name' => 'time_slot_id', 'value' => '9999'],
                    ],
                ],
                [
                    'id' => 4002,
                    'product_id' => 202, // The authentic booking product
                    'name' => 'Private Video Call',
                    'properties' => [
                        ['name' => 'availability_date', 'value' => '2026-11-15'],
                        ['name' => 'time_slot_id', 'value' => '78'],
                    ],
                ],
            ],
        ];

        $resolved = $this->resolver->resolve($order);

        $this->assertNotNull($resolved);
        $this->assertSame(4002, $resolved['line_item_id']);
        $this->assertSame(202, $resolved['product_id']);
        $this->assertSame('2026-11-15', $resolved['availability_date']);
        $this->assertSame(78, $resolved['time_slot_id']);
    }

    public function test_sequential_matching_hierarchy_product_id_overrides_handle_and_sku(): void
    {
        config([
            'booking.product_ids' => [301],
            'booking.product_handles' => ['booking-session'],
            'booking.product_skus' => ['APPT-SKU'],
        ]);

        $order = [
            'id' => 9003,
            'email' => 'sam@example.com',
            'line_items' => [
                [
                    'id' => 6001,
                    'product_id' => 301, // Matches primary product_id
                    'handle' => 'some-other-handle',
                    'sku' => 'OTHER-SKU',
                    'properties' => [
                        ['name' => 'availability_date', 'value' => '2026-12-01'],
                        ['name' => 'time_slot_id', 'value' => '12'],
                    ],
                ],
            ],
        ];

        $resolved = $this->resolver->resolve($order);

        $this->assertNotNull($resolved);
        $this->assertSame(301, $resolved['product_id']);
        $this->assertSame(12, $resolved['time_slot_id']);
    }

    public function test_sequential_matching_falls_back_to_handle_when_product_id_unmatched(): void
    {
        config([
            'booking.product_ids' => [9999], // Does not match
            'booking.product_handles' => ['private-reading-session'],
            'booking.product_skus' => ['SKU-123'],
        ]);

        $order = [
            'id' => 9004,
            'email' => 'fallback@example.com',
            'line_items' => [
                [
                    'id' => 6002,
                    'product_id' => 555,
                    'handle' => 'private-reading-session', // Matches fallback 1
                    'sku' => 'DIFFERENT-SKU',
                    'properties' => [
                        ['name' => 'availability_date', 'value' => '2026-12-05'],
                        ['name' => 'time_slot_id', 'value' => '24'],
                    ],
                ],
            ],
        ];

        $resolved = $this->resolver->resolve($order);

        $this->assertNotNull($resolved);
        $this->assertSame(6002, $resolved['line_item_id']);
        $this->assertSame('2026-12-05', $resolved['availability_date']);
        $this->assertSame(24, $resolved['time_slot_id']);
    }

    public function test_sequential_matching_falls_back_to_sku_when_product_id_and_handle_unmatched(): void
    {
        config([
            'booking.product_ids' => [9999],
            'booking.product_handles' => ['non-matching-handle'],
            'booking.product_skus' => ['TARGET-APPT-SKU'],
        ]);

        $order = [
            'id' => 9005,
            'email' => 'sku@example.com',
            'line_items' => [
                [
                    'id' => 6003,
                    'product_id' => 777,
                    'handle' => 'other-handle',
                    'sku' => 'TARGET-APPT-SKU', // Matches fallback 2
                    'properties' => [
                        ['name' => 'availability_date', 'value' => '2026-12-10'],
                        ['name' => 'time_slot_id', 'value' => '36'],
                    ],
                ],
            ],
        ];

        $resolved = $this->resolver->resolve($order);

        $this->assertNotNull($resolved);
        $this->assertSame(6003, $resolved['line_item_id']);
        $this->assertSame('2026-12-10', $resolved['availability_date']);
        $this->assertSame(36, $resolved['time_slot_id']);
    }

    public function test_returns_null_when_order_has_no_matching_product(): void
    {
        config(['booking.product_ids' => [101]]);

        $order = [
            'id' => 9006,
            'email' => 'unmatched@example.com',
            'line_items' => [
                [
                    'id' => 7001,
                    'product_id' => 999, // Unmatched
                    'properties' => [
                        ['name' => 'availability_date', 'value' => '2026-12-01'],
                        ['name' => 'time_slot_id', 'value' => '12'],
                    ],
                ],
            ],
        ];

        $resolved = $this->resolver->resolve($order);
        $this->assertNull($resolved);
    }

    public function test_returns_null_when_slot_id_is_missing_or_empty(): void
    {
        config(['booking.product_ids' => [101]]);

        $order = [
            'id' => 9007,
            'email' => 'missing_slot@example.com',
            'line_items' => [
                [
                    'id' => 7002,
                    'product_id' => 101,
                    'properties' => [
                        ['name' => 'availability_date', 'value' => '2026-12-01'],
                        // time_slot_id missing
                    ],
                ],
            ],
        ];

        $resolved = $this->resolver->resolve($order);
        $this->assertNull($resolved);
    }
}
