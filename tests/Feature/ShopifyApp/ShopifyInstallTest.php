<?php

namespace Tests\Feature\ShopifyApp;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyInstallTest extends TestCase
{
    use RefreshDatabase;

    protected const SHOP = 'client-store.myshopify.com';

    protected const SECRET = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bundles.store_id' => null,
            'bundles.client_id' => 'client-id',
            'bundles.client_secret' => self::SECRET,
        ]);
    }

    protected function sessionToken(array $overrides = []): string
    {
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $b64(json_encode(array_merge([
            'aud' => 'client-id',
            'dest' => 'https://'.self::SHOP,
            'exp' => time() + 60,
            'nbf' => time() - 5,
        ], $overrides)));
        $signature = $b64(hash_hmac('sha256', "{$header}.{$payload}", self::SECRET, true));

        return "{$header}.{$payload}.{$signature}";
    }

    protected function signedQuery(array $params): array
    {
        ksort($params);
        $message = collect($params)->map(fn ($v, $k) => "{$k}={$v}")->implode('&');
        $params['hmac'] = hash_hmac('sha256', $message, self::SECRET);

        return $params;
    }

    public function test_session_token_is_exchanged_and_token_saved_on_the_store(): void
    {
        Http::fake([self::SHOP.'/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_new', 'scope' => 'read_products'])]);

        $this->get('/shopify-app?shop='.self::SHOP.'&id_token='.$this->sessionToken())->assertOk();

        $store = Store::where('domain', self::SHOP)->firstOrFail();
        $this->assertSame('shpat_new', $store->app_admin_access_token);
        $this->assertSame('client-id', $store->app_client_id);
        $this->assertSame(self::SECRET, $store->webhook_secret);
        Http::assertSent(fn ($r) => $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:token-exchange'
            && $r['requested_token_type'] === 'urn:shopify:params:oauth:token-type:offline-access-token');
    }

    public function test_invalid_session_token_does_not_create_a_store(): void
    {
        Http::fake();

        $this->get('/shopify-app?shop='.self::SHOP.'&id_token='.$this->sessionToken(['aud' => 'someone-else']))->assertOk();

        $this->assertDatabaseCount('stores', 0);
        Http::assertNothingSent();
    }

    public function test_existing_token_is_not_exchanged_again(): void
    {
        Store::create(['name' => 'x', 'domain' => self::SHOP, 'app_admin_access_token' => 'shpat_old']);
        Http::fake();

        $this->get('/shopify-app?shop='.self::SHOP.'&id_token='.$this->sessionToken())->assertOk();

        Http::assertNothingSent();
        $this->assertSame('shpat_old', Store::first()->app_admin_access_token);
    }

    public function test_install_request_redirects_to_authorize_and_callback_saves_token(): void
    {
        Http::fake([self::SHOP.'/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_code'])]);

        $response = $this->get('/shopify-app?'.http_build_query($this->signedQuery(['shop' => self::SHOP, 'timestamp' => '1700000000'])));
        $response->assertRedirectContains('https://'.self::SHOP.'/admin/oauth/authorize');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $authorize);
        $this->assertSame('client-id', $authorize['client_id']);

        $callback = $this->signedQuery(['shop' => self::SHOP, 'code' => 'abc', 'state' => $authorize['state'], 'timestamp' => '1700000001']);
        $this->get('/shopify-app?'.http_build_query($callback))->assertRedirectContains('admin.shopify.com/store/client-store/apps/client-id');

        $this->assertSame('shpat_code', Store::where('domain', self::SHOP)->firstOrFail()->app_admin_access_token);
    }

    public function test_callback_with_bad_hmac_or_state_is_rejected(): void
    {
        Http::fake();
        Cache::put('bundles.install_state.good', self::SHOP, 60);

        $this->get('/shopify-app?'.http_build_query(['shop' => self::SHOP, 'code' => 'abc', 'state' => 'good', 'hmac' => 'bad']))->assertOk();
        $this->get('/shopify-app?'.http_build_query($this->signedQuery(['shop' => self::SHOP, 'code' => 'abc', 'state' => 'forged'])))->assertOk();

        $this->assertDatabaseCount('stores', 0);
        Http::assertNothingSent();
    }
}
