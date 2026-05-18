<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\AI;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Phase F — locale resolution priority + welcome message seeding.
 *
 * Priority:
 *   1. shopify_locale field
 *   2. locale field
 *   3. Accept-Language header
 *   4. shop_settings.default_locale_override
 *   5. config('sales.locale.fallback', 'en')
 *
 * Result is clamped to shop_settings.allowed_locales_json when set.
 */
class LocaleResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();
        config(['sales.locale.fallback' => 'en']);
    }

    public function test_shopify_locale_wins_over_everything(): void
    {
        ShopSetting::factory()
            ->forShop('demo.myshopify.com')
            ->withLocaleOverride('it')
            ->create();

        $response = $this->withHeaders(['Accept-Language' => 'de-DE,de;q=0.9'])
            ->postJson('/api/v1/ai/chat/start', [
                'shop_domain' => 'demo.myshopify.com',
                'shopify_locale' => 'fr',
                'locale' => 'es',
            ]);

        $response->assertStatus(201);

        $sessionId = $response->json('data.session_id');
        $conversation = AiConversation::query()->where('session_id', $sessionId)->firstOrFail();
        $this->assertSame('fr', $conversation->locale);
    }

    public function test_locale_falls_through_to_accept_language(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'fr-CA,fr;q=0.8,en;q=0.5'])
            ->postJson('/api/v1/ai/chat/start', [
                'shop_domain' => 'demo.myshopify.com',
            ]);

        $response->assertStatus(201);
        $sessionId = $response->json('data.session_id');
        $this->assertSame('fr', AiConversation::query()->where('session_id', $sessionId)->value('locale'));
    }

    public function test_locale_falls_through_to_shop_setting_override(): void
    {
        ShopSetting::factory()
            ->forShop('demo.myshopify.com')
            ->withLocaleOverride('de')
            ->create();

        // Explicit empty Accept-Language so resolver falls through to shop
        // override rather than picking up a framework / test default.
        $response = $this->withHeaders(['Accept-Language' => ''])
            ->postJson('/api/v1/ai/chat/start', [
                'shop_domain' => 'demo.myshopify.com',
            ]);

        $response->assertStatus(201);
        $sessionId = $response->json('data.session_id');
        $this->assertSame('de', AiConversation::query()->where('session_id', $sessionId)->value('locale'));
    }

    public function test_locale_falls_through_to_config_default(): void
    {
        $response = $this->postJson('/api/v1/ai/chat/start', [
            'shop_domain' => 'demo.myshopify.com',
        ]);

        $response->assertStatus(201);
        $sessionId = $response->json('data.session_id');
        $this->assertSame('en', AiConversation::query()->where('session_id', $sessionId)->value('locale'));
    }

    public function test_disallowed_locale_falls_back_to_shop_override(): void
    {
        ShopSetting::factory()
            ->forShop('demo.myshopify.com')
            ->withLocaleOverride('de')
            ->withAllowedLocales(['de', 'it'])
            ->create();

        // 'fr' rejected by allow-list, Accept-Language 'es' also disallowed
        // -> falls through to override 'de'.
        $response = $this->withHeaders(['Accept-Language' => 'es'])
            ->postJson('/api/v1/ai/chat/start', [
                'shop_domain' => 'demo.myshopify.com',
                'shopify_locale' => 'fr',
            ]);

        $response->assertStatus(201);
        $sessionId = $response->json('data.session_id');
        $this->assertSame('de', AiConversation::query()->where('session_id', $sessionId)->value('locale'));
    }

    public function test_welcome_message_seeded_when_configured_for_locale(): void
    {
        ShopSetting::factory()
            ->forShop('demo.myshopify.com')
            ->withWelcomeMessages([
                'en' => 'Hi there! How can I help today?',
                'fr' => 'Bonjour ! Comment puis-je vous aider ?',
            ])
            ->create();

        $response = $this->postJson('/api/v1/ai/chat/start', [
            'shop_domain' => 'demo.myshopify.com',
            'shopify_locale' => 'fr',
        ]);

        $response->assertStatus(201);
        $sessionId = $response->json('data.session_id');
        $conversation = AiConversation::query()->where('session_id', $sessionId)->firstOrFail();

        $welcome = $conversation->messages()
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->first();

        $this->assertNotNull($welcome);
        $this->assertSame('Bonjour ! Comment puis-je vous aider ?', $welcome->message);
        $this->assertSame('welcome', $welcome->metadata['source'] ?? null);
        $this->assertSame('fr', $welcome->metadata['locale'] ?? null);
    }

    public function test_no_welcome_message_when_locale_unconfigured(): void
    {
        ShopSetting::factory()
            ->forShop('demo.myshopify.com')
            ->withWelcomeMessages(['en' => 'Hi!'])
            ->create();

        $response = $this->postJson('/api/v1/ai/chat/start', [
            'shop_domain' => 'demo.myshopify.com',
            'shopify_locale' => 'de',
        ]);

        $response->assertStatus(201);
        $sessionId = $response->json('data.session_id');
        $conversation = AiConversation::query()->where('session_id', $sessionId)->firstOrFail();

        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_locale_cached_in_redis_under_session_key(): void
    {
        $response = $this->postJson('/api/v1/ai/chat/start', [
            'shop_domain' => 'demo.myshopify.com',
            'shopify_locale' => 'it',
        ]);

        $response->assertStatus(201);
        $sessionId = $response->json('data.session_id');

        $this->assertSame('it', Cache::get('ai:locale:'.$sessionId));
    }
}
