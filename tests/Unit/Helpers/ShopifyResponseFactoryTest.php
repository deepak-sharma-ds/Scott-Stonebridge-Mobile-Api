<?php

namespace Tests\Unit\Helpers;

use Tests\Helpers\ShopifyResponseFactory;
use Tests\TestCase;

class ShopifyResponseFactoryTest extends TestCase
{
    public function test_product_generates_valid_structure(): void
    {
        $product = ShopifyResponseFactory::product();

        $this->assertArrayHasKey('id', $product);
        $this->assertArrayHasKey('title', $product);
        $this->assertArrayHasKey('handle', $product);
        $this->assertArrayHasKey('description', $product);
        $this->assertArrayHasKey('vendor', $product);
        $this->assertArrayHasKey('productType', $product);
        $this->assertArrayHasKey('tags', $product);
        $this->assertArrayHasKey('availableForSale', $product);
        $this->assertArrayHasKey('images', $product);
        $this->assertArrayHasKey('variants', $product);
        $this->assertArrayHasKey('options', $product);
        $this->assertArrayHasKey('publishedAt', $product);
        $this->assertArrayHasKey('updatedAt', $product);

        $this->assertIsArray($product['images']);
        $this->assertIsArray($product['variants']);
        $this->assertIsArray($product['options']);
        $this->assertIsBool($product['availableForSale']);
    }

    public function test_product_accepts_overrides(): void
    {
        $product = ShopifyResponseFactory::product([
            'title' => 'Custom Product',
            'handle' => 'custom-handle',
            'vendor' => 'Custom Vendor',
        ]);

        $this->assertEquals('Custom Product', $product['title']);
        $this->assertEquals('custom-handle', $product['handle']);
        $this->assertEquals('Custom Vendor', $product['vendor']);
    }

    public function test_product_variant_generates_valid_structure(): void
    {
        $variant = ShopifyResponseFactory::productVariant();

        $this->assertArrayHasKey('id', $variant);
        $this->assertArrayHasKey('title', $variant);
        $this->assertArrayHasKey('sku', $variant);
        $this->assertArrayHasKey('availableForSale', $variant);
        $this->assertArrayHasKey('quantityAvailable', $variant);
        $this->assertArrayHasKey('price', $variant);
        $this->assertArrayHasKey('selectedOptions', $variant);

        $this->assertIsArray($variant['price']);
        $this->assertArrayHasKey('amount', $variant['price']);
        $this->assertArrayHasKey('currencyCode', $variant['price']);
    }

    public function test_cart_generates_valid_structure(): void
    {
        $cart = ShopifyResponseFactory::cart();

        $this->assertArrayHasKey('id', $cart);
        $this->assertArrayHasKey('lines', $cart);
        $this->assertArrayHasKey('checkoutUrl', $cart);
        $this->assertArrayHasKey('cost', $cart);
        $this->assertArrayHasKey('buyerIdentity', $cart);
        $this->assertArrayHasKey('createdAt', $cart);
        $this->assertArrayHasKey('updatedAt', $cart);

        $this->assertIsArray($cart['lines']);
        $this->assertIsArray($cart['cost']);
        $this->assertArrayHasKey('subtotalAmount', $cart['cost']);
        $this->assertArrayHasKey('totalAmount', $cart['cost']);
    }

    public function test_cart_calculates_totals_from_line_items(): void
    {
        $cart = ShopifyResponseFactory::cart([
            'lineItems' => [
                ShopifyResponseFactory::cartLineItem([
                    'quantity' => 2,
                    'merchandise' => [
                        'price' => ['amount' => '10.00', 'currencyCode' => 'GBP'],
                    ],
                ]),
                ShopifyResponseFactory::cartLineItem([
                    'quantity' => 1,
                    'merchandise' => [
                        'price' => ['amount' => '15.00', 'currencyCode' => 'GBP'],
                    ],
                ]),
            ],
        ]);

        $subtotal = (float) $cart['cost']['subtotalAmount']['amount'];
        $this->assertEquals(35.00, $subtotal);
    }

    public function test_cart_line_item_generates_valid_structure(): void
    {
        $lineItem = ShopifyResponseFactory::cartLineItem();

        $this->assertArrayHasKey('id', $lineItem);
        $this->assertArrayHasKey('quantity', $lineItem);
        $this->assertArrayHasKey('merchandise', $lineItem);

        $this->assertIsArray($lineItem['merchandise']);
        $this->assertArrayHasKey('id', $lineItem['merchandise']);
        $this->assertArrayHasKey('title', $lineItem['merchandise']);
        $this->assertArrayHasKey('price', $lineItem['merchandise']);
        $this->assertArrayHasKey('product', $lineItem['merchandise']);
    }

    public function test_order_generates_valid_structure(): void
    {
        $order = ShopifyResponseFactory::order();

        $this->assertArrayHasKey('id', $order);
        $this->assertArrayHasKey('name', $order);
        $this->assertArrayHasKey('orderNumber', $order);
        $this->assertArrayHasKey('processedAt', $order);
        $this->assertArrayHasKey('financialStatus', $order);
        $this->assertArrayHasKey('fulfillmentStatus', $order);
        $this->assertArrayHasKey('totalPriceV2', $order);
        $this->assertArrayHasKey('subtotalPriceV2', $order);
        $this->assertArrayHasKey('totalTaxV2', $order);
        $this->assertArrayHasKey('lineItems', $order);
        $this->assertArrayHasKey('shippingAddress', $order);

        $this->assertIsArray($order['lineItems']);
        $this->assertIsArray($order['shippingAddress']);
    }

    public function test_order_line_item_generates_valid_structure(): void
    {
        $lineItem = ShopifyResponseFactory::orderLineItem();

        $this->assertArrayHasKey('id', $lineItem);
        $this->assertArrayHasKey('title', $lineItem);
        $this->assertArrayHasKey('quantity', $lineItem);
        $this->assertArrayHasKey('variant', $lineItem);
        $this->assertArrayHasKey('originalTotalPrice', $lineItem);

        $this->assertIsArray($lineItem['variant']);
        $this->assertIsArray($lineItem['originalTotalPrice']);
    }

    public function test_customer_generates_valid_structure(): void
    {
        $customer = ShopifyResponseFactory::customer();

        $this->assertArrayHasKey('id', $customer);
        $this->assertArrayHasKey('email', $customer);
        $this->assertArrayHasKey('firstName', $customer);
        $this->assertArrayHasKey('lastName', $customer);
        $this->assertArrayHasKey('phone', $customer);
        $this->assertArrayHasKey('addresses', $customer);
        $this->assertArrayHasKey('tags', $customer);
        $this->assertArrayHasKey('acceptsMarketing', $customer);
        $this->assertArrayHasKey('createdAt', $customer);

        $this->assertIsArray($customer['addresses']);
        $this->assertIsArray($customer['tags']);
        $this->assertIsBool($customer['acceptsMarketing']);
    }

    public function test_address_generates_valid_structure(): void
    {
        $address = ShopifyResponseFactory::address();

        $this->assertArrayHasKey('id', $address);
        $this->assertArrayHasKey('address1', $address);
        $this->assertArrayHasKey('city', $address);
        $this->assertArrayHasKey('province', $address);
        $this->assertArrayHasKey('country', $address);
        $this->assertArrayHasKey('zip', $address);
        $this->assertArrayHasKey('firstName', $address);
        $this->assertArrayHasKey('lastName', $address);
    }

    public function test_collection_generates_valid_structure(): void
    {
        $collection = ShopifyResponseFactory::collection();

        $this->assertArrayHasKey('id', $collection);
        $this->assertArrayHasKey('title', $collection);
        $this->assertArrayHasKey('handle', $collection);
        $this->assertArrayHasKey('description', $collection);
        $this->assertArrayHasKey('image', $collection);
        $this->assertArrayHasKey('updatedAt', $collection);

        $this->assertIsArray($collection['image']);
    }

    public function test_paginated_response_generates_edges_structure(): void
    {
        $items = [
            ['id' => '1', 'title' => 'Item 1'],
            ['id' => '2', 'title' => 'Item 2'],
        ];

        $paginated = ShopifyResponseFactory::paginatedResponse($items, true);

        $this->assertArrayHasKey('edges', $paginated);
        $this->assertArrayHasKey('pageInfo', $paginated);
        $this->assertCount(2, $paginated['edges']);

        foreach ($paginated['edges'] as $edge) {
            $this->assertArrayHasKey('node', $edge);
            $this->assertArrayHasKey('cursor', $edge);
        }

        $this->assertArrayHasKey('hasNextPage', $paginated['pageInfo']);
        $this->assertTrue($paginated['pageInfo']['hasNextPage']);
    }

    public function test_error_response_generates_valid_structure(): void
    {
        $error = ShopifyResponseFactory::errorResponse('Test error', 'TEST_ERROR');

        $this->assertArrayHasKey('errors', $error);
        $this->assertIsArray($error['errors']);
        $this->assertCount(1, $error['errors']);
        $this->assertEquals('Test error', $error['errors'][0]['message']);
        $this->assertEquals('TEST_ERROR', $error['errors'][0]['extensions']['code']);
    }

    public function test_success_response_wraps_data_correctly(): void
    {
        $productData = ShopifyResponseFactory::product();
        $response = ShopifyResponseFactory::successResponse('product', $productData);

        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('product', $response['data']);
        $this->assertEquals($productData, $response['data']['product']);
    }

    public function test_factory_methods_generate_unique_ids(): void
    {
        $product1 = ShopifyResponseFactory::product();
        $product2 = ShopifyResponseFactory::product();

        $this->assertNotEquals($product1['id'], $product2['id']);
    }

    public function test_factory_methods_generate_realistic_data(): void
    {
        $product = ShopifyResponseFactory::product();

        // Check that IDs follow Shopify GID format
        $this->assertStringStartsWith('gid://shopify/Product/', $product['id']);

        // Check that variants have proper structure
        $this->assertNotEmpty($product['variants']);
        $variant = $product['variants'][0];
        $this->assertStringStartsWith('gid://shopify/ProductVariant/', $variant['id']);

        // Check that prices are properly formatted
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $variant['price']['amount']);
    }
}
