<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\AI;

use App\Models\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopSettingsSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'scott-stonebridge.myshopify.com';

    public function test_chat_start_with_theme_settings_upserts_shop_settings(): void
    {
        $payload = [
            'shop_domain' => self::SHOP,
            'locale' => 'en',
            'page_type' => 'home',
            'theme_settings' => [
                'persona_name' => 'Scott Stonebridge AI',
                'avatar_url' => 'https://cdn.shopify.com/files/scott-avatar.png',
                'brand_color' => '#7C3AED',
                'widget_position' => 'bottom-right',
                'greeting_message' => 'Welcome, seeker. How may I guide you today?',
                'free_shipping_threshold' => 50.00,
            ],
        ];

        $response = $this->postJson(route('api.v1.ai.chat.start'), $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.widget.persona_name', 'Scott Stonebridge AI')
            ->assertJsonPath('data.widget.avatar_url', 'https://cdn.shopify.com/files/scott-avatar.png')
            ->assertJsonPath('data.widget.brand_color', '#7C3AED')
            ->assertJsonPath('data.widget.position', 'right');

        $this->assertDatabaseHas('shop_settings', [
            'shop_domain' => self::SHOP,
            'persona_name' => 'Scott Stonebridge AI',
            'avatar_url' => 'https://cdn.shopify.com/files/scott-avatar.png',
            'brand_color' => '#7C3AED',
            'widget_position' => 'right',
            'free_shipping_threshold' => '50.00',
        ]);

        $setting = ShopSetting::query()->where('shop_domain', self::SHOP)->first();
        $this->assertNotNull($setting);
        $this->assertSame('Welcome, seeker. How may I guide you today?', $setting->welcomeFor('en'));
    }

    public function test_chat_start_without_theme_settings_preserves_existing_settings(): void
    {
        ShopSetting::create([
            'shop_domain' => self::SHOP,
            'persona_name' => 'Existing Persona',
            'avatar_url' => 'https://cdn.shopify.com/existing.png',
            'brand_color' => '#FF0000',
            'widget_position' => 'left',
        ]);

        $payload = [
            'shop_domain' => self::SHOP,
            'locale' => 'en',
            'page_type' => 'home',
        ];

        $response = $this->postJson(route('api.v1.ai.chat.start'), $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.widget.persona_name', 'Existing Persona')
            ->assertJsonPath('data.widget.avatar_url', 'https://cdn.shopify.com/existing.png')
            ->assertJsonPath('data.widget.brand_color', '#FF0000')
            ->assertJsonPath('data.widget.position', 'left');
    }

    public function test_dedicated_widget_sync_endpoint_updates_shop_settings(): void
    {
        $payload = [
            'shop_domain' => self::SHOP,
            'locale' => 'en',
            'theme_settings' => [
                'persona_name' => 'Updated Scott AI',
                'avatar_url' => 'https://cdn.shopify.com/avatar2.png',
                'brand_color' => '#10B981',
                'widget_position' => 'bottom-left',
                'greeting_message' => 'Hello from theme customizer!',
                'free_shipping_threshold' => 75.50,
            ],
        ];

        $response = $this->postJson(route('api.v1.ai.widget.sync'), $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.shop_domain', self::SHOP)
            ->assertJsonPath('data.widget.persona_name', 'Updated Scott AI')
            ->assertJsonPath('data.widget.avatar_url', 'https://cdn.shopify.com/avatar2.png')
            ->assertJsonPath('data.widget.brand_color', '#10B981')
            ->assertJsonPath('data.widget.position', 'left')
            ->assertJsonPath('data.free_shipping_threshold', '75.50');

        $this->assertDatabaseHas('shop_settings', [
            'shop_domain' => self::SHOP,
            'persona_name' => 'Updated Scott AI',
            'avatar_url' => 'https://cdn.shopify.com/avatar2.png',
            'brand_color' => '#10B981',
            'widget_position' => 'left',
            'free_shipping_threshold' => '75.50',
        ]);
    }
}
