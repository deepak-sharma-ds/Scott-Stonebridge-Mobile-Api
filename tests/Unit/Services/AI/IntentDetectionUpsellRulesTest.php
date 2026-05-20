<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\DTOs\Chat\CartContextDTO;
use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\IntentDTO;
use App\DTOs\Chat\ProductContextDTO;
use App\Services\AI\IntentDetectionService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Phase C — context-prior rules added in Step 6.
 *
 * cart_help -> upsell_opportunity when cart_total < free_shipping_threshold.
 * product_support -> cross_sell_opportunity when cart has items AND viewed
 *                    product not in cart.
 */
class IntentDetectionUpsellRulesTest extends TestCase
{
    private IntentDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->service = new IntentDetectionService;

        config([
            'sales.upsell.default_free_shipping_threshold' => 50.00,
            'chatbot.intent.confidence_threshold' => 0.65,
        ]);
    }

    public function test_cart_help_promotes_to_upsell_when_below_threshold(): void
    {
        $ctx = new ChatContextDTO(
            pageType: 'cart',
            product: null,
            cart: new CartContextDTO(id: 'c1', itemCount: 1, totalPrice: '30.00', currency: 'GBP', items: []),
            customer: null,
            recentlyViewed: [],
            shopDomain: 'demo.myshopify.com',
            currency: 'GBP',
            locale: 'en',
        );

        $result = $this->service->detect('checkout', $ctx);

        $this->assertSame(IntentDTO::INTENT_UPSELL_OPPORTUNITY, $result->name);
        $this->assertSame(0.8, $result->confidence);
    }

    public function test_cart_help_stays_when_above_threshold(): void
    {
        $ctx = new ChatContextDTO(
            pageType: 'cart',
            product: null,
            cart: new CartContextDTO(id: 'c1', itemCount: 3, totalPrice: '80.00', currency: 'GBP', items: []),
            customer: null,
            recentlyViewed: [],
            shopDomain: 'demo.myshopify.com',
            currency: 'GBP',
            locale: 'en',
        );

        $result = $this->service->detect('checkout', $ctx);

        $this->assertSame(IntentDTO::INTENT_CART_HELP, $result->name);
    }

    public function test_cart_help_stays_when_no_threshold_configured(): void
    {
        config(['sales.upsell.default_free_shipping_threshold' => 0]);

        $ctx = new ChatContextDTO(
            pageType: 'cart',
            product: null,
            cart: new CartContextDTO(id: 'c1', itemCount: 1, totalPrice: '10.00', currency: 'GBP', items: []),
            customer: null,
            recentlyViewed: [],
            shopDomain: 'demo.myshopify.com',
            currency: 'GBP',
            locale: 'en',
        );

        $result = $this->service->detect('checkout', $ctx);

        $this->assertSame(IntentDTO::INTENT_CART_HELP, $result->name);
    }

    public function test_product_support_promotes_to_cross_sell_when_product_not_in_cart(): void
    {
        $ctx = new ChatContextDTO(
            pageType: 'product',
            product: new ProductContextDTO(
                id: 'gid://shopify/Product/77',
                handle: 'p',
                title: 'P',
                vendor: null,
                price: null,
                tags: [],
                variants: [],
            ),
            cart: new CartContextDTO(
                id: 'c',
                itemCount: 1,
                totalPrice: '20.00',
                currency: 'GBP',
                items: [['product_id' => 'gid://shopify/Product/10']],
            ),
            customer: null,
            recentlyViewed: [],
            shopDomain: 'demo.myshopify.com',
            currency: 'GBP',
            locale: 'en',
        );

        $result = $this->service->detect('is this waterproof?', $ctx);

        $this->assertSame(IntentDTO::INTENT_CROSS_SELL_OPPORTUNITY, $result->name);
        $this->assertSame(0.7, $result->confidence);
    }

    public function test_product_support_stays_when_product_already_in_cart(): void
    {
        $ctx = new ChatContextDTO(
            pageType: 'product',
            product: new ProductContextDTO(
                id: 'gid://shopify/Product/77',
                handle: 'p',
                title: 'P',
                vendor: null,
                price: null,
                tags: [],
                variants: [],
            ),
            cart: new CartContextDTO(
                id: 'c',
                itemCount: 1,
                totalPrice: '20.00',
                currency: 'GBP',
                items: [['product_id' => 'gid://shopify/Product/77']],
            ),
            customer: null,
            recentlyViewed: [],
            shopDomain: 'demo.myshopify.com',
            currency: 'GBP',
            locale: 'en',
        );

        $result = $this->service->detect('is this waterproof?', $ctx);

        $this->assertSame(IntentDTO::INTENT_PRODUCT_SUPPORT, $result->name);
    }

    public function test_product_support_stays_when_cart_empty(): void
    {
        $ctx = new ChatContextDTO(
            pageType: 'product',
            product: new ProductContextDTO(
                id: 'gid://shopify/Product/77',
                handle: 'p',
                title: 'P',
                vendor: null,
                price: null,
                tags: [],
                variants: [],
            ),
            cart: new CartContextDTO(id: 'c', itemCount: 0, totalPrice: null, currency: null, items: []),
            customer: null,
            recentlyViewed: [],
            shopDomain: 'demo.myshopify.com',
            currency: 'GBP',
            locale: 'en',
        );

        $result = $this->service->detect('is this waterproof?', $ctx);

        $this->assertSame(IntentDTO::INTENT_PRODUCT_SUPPORT, $result->name);
    }
}
