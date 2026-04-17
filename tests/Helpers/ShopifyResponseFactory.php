<?php

namespace Tests\Helpers;

use Illuminate\Support\Str;

/**
 * Factory for generating realistic Shopify API response data for testing
 * 
 * This factory creates mock Shopify GraphQL responses that match the structure
 * expected by DTOs and services. All methods support overrides for customization.
 * 
 * Requirements: 20.4
 */
class ShopifyResponseFactory
{
    /**
     * Generate a product response
     *
     * @param array $overrides Custom values to override defaults
     * @return array
     */
    public static function product(array $overrides = []): array
    {
        $id = $overrides['id'] ?? 'gid://shopify/Product/' . rand(1000000, 9999999);
        $title = $overrides['title'] ?? 'Test Product ' . rand(1, 100);
        $handle = $overrides['handle'] ?? Str::slug($title);

        return array_merge([
            'id' => $id,
            'title' => $title,
            'handle' => $handle,
            'description' => 'This is a test product description with detailed information about the product features and benefits.',
            'vendor' => 'Test Vendor',
            'productType' => 'Test Type',
            'tags' => ['test', 'sample', 'featured'],
            'availableForSale' => true,
            'images' => [
                [
                    'url' => 'https://cdn.shopify.com/test-image-1.jpg',
                    'altText' => 'Product image 1',
                ],
                [
                    'url' => 'https://cdn.shopify.com/test-image-2.jpg',
                    'altText' => 'Product image 2',
                ],
            ],
            'variants' => [
                self::productVariant(['title' => 'Default Title']),
            ],
            'options' => [
                [
                    'name' => 'Size',
                    'values' => ['Small', 'Medium', 'Large'],
                ],
            ],
            'publishedAt' => now()->subDays(30)->toIso8601String(),
            'updatedAt' => now()->subDays(1)->toIso8601String(),
        ], $overrides);
    }

    /**
     * Generate a product variant response
     *
     * @param array $overrides Custom values to override defaults
     * @return array
     */
    public static function productVariant(array $overrides = []): array
    {
        $id = $overrides['id'] ?? 'gid://shopify/ProductVariant/' . rand(1000000, 9999999);

        return array_merge([
            'id' => $id,
            'title' => 'Default Title',
            'sku' => 'TEST-SKU-' . rand(1000, 9999),
            'availableForSale' => true,
            'quantityAvailable' => rand(0, 100),
            'price' => [
                'amount' => number_format(rand(1000, 50000) / 100, 2, '.', ''),
                'currencyCode' => 'GBP',
            ],
            'compareAtPrice' => null,
            'selectedOptions' => [
                [
                    'name' => 'Size',
                    'value' => 'Medium',
                ],
            ],
            'image' => [
                'url' => 'https://cdn.shopify.com/test-variant-image.jpg',
                'altText' => 'Variant image',
            ],
        ], $overrides);
    }

    /**
     * Generate a cart response
     *
     * @param array $overrides Custom values to override defaults
     * @return array
     */
    public static function cart(array $overrides = []): array
    {
        $id = $overrides['id'] ?? 'gid://shopify/Cart/' . Str::uuid();
        $lineItems = $overrides['lineItems'] ?? [
            self::cartLineItem(),
        ];

        // Calculate totals from line items
        $subtotal = array_reduce($lineItems, function ($sum, $item) {
            $price = is_array($item['merchandise']['price']) 
                ? (float) $item['merchandise']['price']['amount']
                : (float) $item['merchandise']['price'];
            return $sum + ($price * $item['quantity']);
        }, 0);

        return array_merge([
            'id' => $id,
            'lines' => $lineItems,
            'checkoutUrl' => 'https://test-store.myshopify.com/cart/c/' . Str::random(32),
            'cost' => [
                'subtotalAmount' => [
                    'amount' => number_format($subtotal, 2, '.', ''),
                    'currencyCode' => 'GBP',
                ],
                'totalAmount' => [
                    'amount' => number_format($subtotal, 2, '.', ''),
                    'currencyCode' => 'GBP',
                ],
            ],
            'buyerIdentity' => null,
            'createdAt' => now()->subHours(2)->toIso8601String(),
            'updatedAt' => now()->subMinutes(5)->toIso8601String(),
        ], $overrides);
    }

    /**
     * Generate a cart line item response
     *
     * @param array $overrides Custom values to override defaults
     * @return array
     */
    public static function cartLineItem(array $overrides = []): array
    {
        $id = $overrides['id'] ?? 'gid://shopify/CartLine/' . Str::uuid();
        $quantity = $overrides['quantity'] ?? rand(1, 5);
        $variantId = 'gid://shopify/ProductVariant/' . rand(1000000, 9999999);
        $productId = 'gid://shopify/Product/' . rand(1000000, 9999999);

        $defaultMerchandise = [
            'id' => $variantId,
            'title' => 'Test Product - Default Title',
            'price' => [
                'amount' => '29.99',
                'currencyCode' => 'GBP',
            ],
            'product' => [
                'id' => $productId,
                'title' => 'Test Product',
                'handle' => 'test-product',
            ],
        ];

        // Merge merchandise if provided in overrides
        $merchandise = isset($overrides['merchandise']) 
            ? array_merge($defaultMerchandise, $overrides['merchandise'])
            : $defaultMerchandise;

        // Ensure product is properly merged if provided
        if (isset($overrides['merchandise']['product'])) {
            $merchandise['product'] = array_merge(
                $defaultMerchandise['product'],
                $overrides['merchandise']['product']
            );
        }

        // Ensure price is properly merged if provided
        if (isset($overrides['merchandise']['price'])) {
            $merchandise['price'] = array_merge(
                $defaultMerchandise['price'],
                $overrides['merchandise']['price']
            );
        }

        unset($overrides['merchandise']); // Remove to avoid double merge

        return array_merge([
            'id' => $id,
            'quantity' => $quantity,
            'merchandise' => $merchandise,
        ], $overrides);
    }

    /**
     * Generate an order response
     *
     * @param array $overrides Custom values to override defaults
     * @return array
     */
    public static function order(array $overrides = []): array
    {
        $orderNumber = $overrides['orderNumber'] ?? rand(1000, 9999);
        $id = $overrides['id'] ?? 'gid://shopify/Order/' . rand(1000000, 9999999);
        $lineItems = $overrides['lineItems'] ?? [
            self::orderLineItem(),
        ];

        // Calculate totals from line items
        $subtotal = array_reduce($lineItems, function ($sum, $item) {
            $price = is_array($item['originalTotalPrice']) 
                ? (float) $item['originalTotalPrice']['amount']
                : (float) $item['originalTotalPrice'];
            return $sum + $price;
        }, 0);

        $tax = $subtotal * 0.2; // 20% tax
        $total = $subtotal + $tax;

        return array_merge([
            'id' => $id,
            'name' => '#' . $orderNumber,
            'orderNumber' => $orderNumber,
            'processedAt' => now()->subDays(7)->toIso8601String(),
            'financialStatus' => 'PAID',
            'fulfillmentStatus' => 'FULFILLED',
            'totalPriceV2' => [
                'amount' => number_format($total, 2, '.', ''),
                'currencyCode' => 'GBP',
            ],
            'subtotalPriceV2' => [
                'amount' => number_format($subtotal, 2, '.', ''),
                'currencyCode' => 'GBP',
            ],
            'totalTaxV2' => [
                'amount' => number_format($tax, 2, '.', ''),
                'currencyCode' => 'GBP',
            ],
            'lineItems' => $lineItems,
            'shippingAddress' => [
                'address1' => '123 Test Street',
                'address2' => 'Apt 4B',
                'city' => 'London',
                'province' => 'England',
                'country' => 'United Kingdom',
                'zip' => 'SW1A 1AA',
            ],
        ], $overrides);
    }

    /**
     * Generate an order line item response
     *
     * @param array $overrides Custom values to override defaults
     * @return array
     */
    public static function orderLineItem(array $overrides = []): array
    {
        $quantity = $overrides['quantity'] ?? rand(1, 3);
        $price = $overrides['price'] ?? rand(1000, 10000) / 100;
        $total = $quantity * $price;

        return array_merge([
            'id' => 'gid://shopify/LineItem/' . rand(1000000, 9999999),
            'title' => 'Test Product',
            'quantity' => $quantity,
            'variant' => [
                'id' => 'gid://shopify/ProductVariant/' . rand(1000000, 9999999),
                'title' => 'Default Title',
                'sku' => 'TEST-SKU-' . rand(1000, 9999),
                'price' => [
                    'amount' => number_format($price, 2, '.', ''),
                    'currencyCode' => 'GBP',
                ],
            ],
            'originalTotalPrice' => [
                'amount' => number_format($total, 2, '.', ''),
                'currencyCode' => 'GBP',
            ],
        ], $overrides);
    }

    /**
     * Generate a customer response
     *
     * @param array $overrides Custom values to override defaults
     * @return array
     */
    public static function customer(array $overrides = []): array
    {
        $id = $overrides['id'] ?? 'gid://shopify/Customer/' . rand(1000000, 9999999);
        $firstName = $overrides['firstName'] ?? 'John';
        $lastName = $overrides['lastName'] ?? 'Doe';
        $email = $overrides['email'] ?? strtolower($firstName . '.' . $lastName . '@example.com');

        return array_merge([
            'id' => $id,
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phone' => '+44 20 7946 0958',
            'addresses' => [
                self::address(),
            ],
            'tags' => ['vip', 'newsletter'],
            'acceptsMarketing' => true,
            'createdAt' => now()->subMonths(6)->toIso8601String(),
        ], $overrides);
    }

    /**
     * Generate an address response
     *
     * @param array $overrides Custom values to override defaults
     * @return array
     */
    public static function address(array $overrides = []): array
    {
        return array_merge([
            'id' => 'gid://shopify/MailingAddress/' . rand(1000000, 9999999),
            'address1' => '123 Test Street',
            'address2' => 'Apt 4B',
            'city' => 'London',
            'province' => 'England',
            'country' => 'United Kingdom',
            'zip' => 'SW1A 1AA',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'phone' => '+44 20 7946 0958',
        ], $overrides);
    }

    /**
     * Generate a collection response
     *
     * @param array $overrides Custom values to override defaults
     * @return array
     */
    public static function collection(array $overrides = []): array
    {
        $id = $overrides['id'] ?? 'gid://shopify/Collection/' . rand(1000000, 9999999);
        $title = $overrides['title'] ?? 'Test Collection ' . rand(1, 100);
        $handle = $overrides['handle'] ?? Str::slug($title);

        return array_merge([
            'id' => $id,
            'title' => $title,
            'handle' => $handle,
            'description' => 'This is a test collection containing curated products.',
            'image' => [
                'url' => 'https://cdn.shopify.com/test-collection-image.jpg',
                'altText' => 'Collection image',
            ],
            'updatedAt' => now()->subDays(5)->toIso8601String(),
        ], $overrides);
    }

    /**
     * Generate a paginated response with edges/nodes structure
     *
     * @param array $items Array of items to wrap in edges/nodes
     * @param bool $hasNextPage Whether there are more pages
     * @param string|null $endCursor Cursor for pagination
     * @return array
     */
    public static function paginatedResponse(
        array $items,
        bool $hasNextPage = false,
        ?string $endCursor = null
    ): array {
        $edges = array_map(fn($item) => [
            'node' => $item,
            'cursor' => base64_encode('cursor_' . ($item['id'] ?? rand(1000, 9999))),
        ], $items);

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNextPage,
                'hasPreviousPage' => false,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $endCursor ?? end($edges)['cursor'] ?? null,
            ],
        ];
    }

    /**
     * Generate a GraphQL error response
     *
     * @param string $message Error message
     * @param string $code Error code
     * @return array
     */
    public static function errorResponse(
        string $message = 'An error occurred',
        string $code = 'INTERNAL_ERROR'
    ): array {
        return [
            'errors' => [
                [
                    'message' => $message,
                    'extensions' => [
                        'code' => $code,
                    ],
                ],
            ],
        ];
    }

    /**
     * Generate a successful GraphQL response wrapper
     *
     * @param string $queryName The GraphQL query name (e.g., 'product', 'cart')
     * @param array $data The data to wrap
     * @return array
     */
    public static function successResponse(string $queryName, array $data): array
    {
        return [
            'data' => [
                $queryName => $data,
            ],
        ];
    }
}
