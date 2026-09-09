<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\AI;

use App\Models\AiConversation;
use App\Models\AiCustomerSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Customer Account OAuth bridge coverage:
 *   GET /api/v1/ai/oauth/customer/start    — PKCE redirect with state in cache
 *   GET /api/v1/ai/oauth/customer/callback — exchange + persist token + popup
 *   GET /api/v1/ai/oauth/customer/status   — flips to authenticated after callback
 */
class CustomerOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'demo.myshopify.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();
        config([
            'chatbot.oauth.client_id' => 'test-client-id',
            'chatbot.oauth.redirect_uri' => 'https://app.test/api/v1/ai/oauth/customer/callback',
            'chatbot.oauth.scopes' => ['customer-account-api:full'],
            'chatbot.oauth.pkce_session_ttl' => 600,
            'chatbot.oauth.token_ttl_seconds' => 3600,
        ]);
    }

    public function test_start_redirects_to_authorization_endpoint_with_pkce_challenge(): void
    {
        $convo = AiConversation::factory()->create(['shop_domain' => self::SHOP]);

        Http::fake([
            'https://'.self::SHOP.'/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://'.self::SHOP.'/oauth/authorize',
                'token_endpoint' => 'https://'.self::SHOP.'/oauth/token',
            ]),
        ]);

        $response = $this->get(sprintf(
            '/api/v1/ai/oauth/customer/start?session_id=%s&shop_domain=%s',
            $convo->session_id,
            self::SHOP,
        ));

        $response->assertStatus(302);
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringStartsWith('https://'.self::SHOP.'/oauth/authorize?', $location);

        parse_str(parse_url($location, PHP_URL_QUERY) ?? '', $query);
        $this->assertSame('test-client-id', $query['client_id'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertSame('customer-account-api:full', $query['scope'] ?? null);
        $this->assertNotEmpty($query['code_challenge'] ?? null);
        $this->assertNotEmpty($query['state'] ?? null);

        $state = $query['state'];
        $stashed = Cache::get('ai:oauth:state:'.$state);
        $this->assertIsArray($stashed);
        $this->assertSame($convo->session_id, $stashed['session_id']);
        $this->assertSame(self::SHOP, $stashed['shop_domain']);
        $this->assertNotEmpty($stashed['verifier']);

        $expectedChallenge = rtrim(
            strtr(base64_encode(hash('sha256', $stashed['verifier'], true)), '+/', '-_'),
            '=',
        );
        $this->assertSame($expectedChallenge, $query['code_challenge']);
    }

    public function test_start_rejects_invalid_session_id(): void
    {
        $response = $this->get(sprintf(
            '/api/v1/ai/oauth/customer/start?session_id=%s&shop_domain=%s',
            '00000000-0000-0000-0000-000000000000',
            self::SHOP,
        ));

        $response->assertStatus(302);
        $response->assertSessionHasErrors('session_id');
    }

    public function test_callback_exchanges_code_persists_token_and_returns_close_page(): void
    {
        $convo = AiConversation::factory()->create(['shop_domain' => self::SHOP]);
        $state = 'fixed-state-token';

        Cache::put('ai:oauth:state:'.$state, [
            'verifier' => 'fixed-verifier-value-fixed-verifier-value-fixed-verifier-value',
            'session_id' => $convo->session_id,
            'shop_domain' => self::SHOP,
        ], 600);

        Http::fake([
            'https://'.self::SHOP.'/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://'.self::SHOP.'/oauth/authorize',
                'token_endpoint' => 'https://'.self::SHOP.'/oauth/token',
            ]),
            'https://'.self::SHOP.'/oauth/token' => Http::response([
                'access_token' => 'shpca_test_token_value',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
        ]);

        $response = $this->get('/api/v1/ai/oauth/customer/callback?'.http_build_query([
            'code' => 'auth-code-abc',
            'state' => $state,
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->assertStringContainsString('scs_oauth_done', $response->getContent());
        $this->assertStringContainsString($convo->session_id, $response->getContent());

        $row = AiCustomerSession::query()->where('session_id', $convo->session_id)->first();
        $this->assertNotNull($row);
        $this->assertSame('shpca_test_token_value', $row->customer_access_token);
        $this->assertTrue($row->expires_at->isFuture());

        $this->assertNull(Cache::get('ai:oauth:state:'.$state));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://'.self::SHOP.'/oauth/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'auth-code-abc'
                && ! empty($request['code_verifier']);
        });
    }

    public function test_callback_with_unknown_state_returns_400_error_page(): void
    {
        $response = $this->get('/api/v1/ai/oauth/customer/callback?code=x&state=missing');

        $response->assertStatus(400);
        $this->assertStringContainsString('expired', $response->getContent());
    }

    public function test_status_reports_unauthenticated_when_no_session(): void
    {
        $sessionId = '11111111-2222-3333-4444-555555555555';

        $response = $this->getJson('/api/v1/ai/oauth/customer/status?session_id='.$sessionId);

        $response->assertOk();
        $response->assertExactJson(['authenticated' => false]);
    }

    public function test_status_reports_authenticated_after_callback(): void
    {
        $convo = AiConversation::factory()->create(['shop_domain' => self::SHOP]);
        AiCustomerSession::create([
            'session_id' => $convo->session_id,
            'customer_access_token' => 'shpca_active',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->getJson('/api/v1/ai/oauth/customer/status?session_id='.$convo->session_id);

        $response->assertOk();
        $response->assertJsonPath('authenticated', true);
        $this->assertNotEmpty($response->json('expires_at'));
    }

    public function test_status_reports_unauthenticated_when_token_expired(): void
    {
        $convo = AiConversation::factory()->create(['shop_domain' => self::SHOP]);
        AiCustomerSession::create([
            'session_id' => $convo->session_id,
            'customer_access_token' => 'shpca_old',
            'expires_at' => now()->subMinute(),
        ]);

        $response = $this->getJson('/api/v1/ai/oauth/customer/status?session_id='.$convo->session_id);

        $response->assertOk();
        $response->assertExactJson(['authenticated' => false]);
    }

    public function test_logout_invalidates_session(): void
    {
        $convo = AiConversation::factory()->create(['shop_domain' => self::SHOP]);
        $session = AiCustomerSession::create([
            'session_id' => $convo->session_id,
            'customer_access_token' => 'shpca_active',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/v1/ai/oauth/customer/logout', [
            'session_id' => $convo->session_id,
        ]);

        $response->assertOk();
        $response->assertExactJson(['success' => true, 'authenticated' => false]);

        $session->refresh();
        $this->assertTrue($session->isExpired());
        $this->assertSame('', $session->customer_access_token);
    }
}
