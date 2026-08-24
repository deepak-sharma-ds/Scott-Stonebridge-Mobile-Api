<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\AI;

use App\Models\AiCustomerSession;
use App\Services\AI\ChatbotConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * GET /api/v1/ai/oauth/customer/{start,callback,status}
 *
 * Shopify Customer Account OAuth bridge — PKCE flow. The chat widget calls
 * `/start` in a popup, we redirect to Shopify's hosted authorization page,
 * Shopify redirects back to `/callback`, we exchange the auth code for an
 * access token and persist it bound to the chat session.
 */
class CustomerOAuthController
{
    private const STATE_PREFIX = 'ai:oauth:state:';

    public function __construct(
        private readonly ChatbotConfigRepository $chatbotConfig,
    ) {}

    public function start(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'uuid', 'exists:ai_conversations,session_id'],
            'shop_domain' => ['required', 'string', 'regex:/^[A-Za-z0-9.\-]+$/'],
        ]);

        $sessionId = (string) $validated['session_id'];
        $shopDomain = (string) $validated['shop_domain'];

        $config = $this->discoverOidcConfig($shopDomain);

        $verifier = Str::random(64);
        $challenge = $this->codeChallenge($verifier);
        $state = bin2hex(random_bytes(16));
        $ttl = (int) config('chatbot.oauth.pkce_session_ttl', 600);

        Cache::put(
            self::STATE_PREFIX.$state,
            [
                'verifier' => $verifier,
                'session_id' => $sessionId,
                'shop_domain' => $shopDomain,
            ],
            $ttl,
        );

        $clientId = (string) config('chatbot.oauth.client_id');
        $scopeString = implode(' ', (array) config('chatbot.oauth.scopes', []));

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => (string) config('chatbot.oauth.redirect_uri'),
            'scope' => $scopeString,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        // Diagnostic — Shopify rejects "scope invalid" before redirecting back,
        // so log the exact values we sent so we can compare against the
        // Headless app's enabled scopes in admin.
        Log::channel('ai')->info('oauth.authorize_start', [
            'session_id' => $sessionId,
            'shop_domain' => $shopDomain,
            'client_id' => $clientId,
            'scope' => $scopeString,
            'authorize_endpoint' => $config['authorization_endpoint'],
        ]);

        return redirect()->away($config['authorization_endpoint'].'?'.$query);
    }

    public function callback(Request $request): Response
    {
        // Unconditional entry log so we can prove the callback was hit even
        // when validation fails before any other log line fires. Truncate the
        // state value so logs don't leak the full nonce.
        Log::channel('ai')->info('oauth.callback_hit', [
            'has_state' => $request->filled('state'),
            'has_code' => $request->filled('code'),
            'has_error' => $request->filled('error'),
            'state_prefix' => substr((string) $request->query('state', ''), 0, 8),
            'query_keys' => array_keys($request->query()),
            'user_agent' => substr((string) $request->userAgent(), 0, 120),
        ]);

        // Shopify can redirect with `error` / `error_description` (user denied,
        // scope rejected) WITHOUT a `code`. Validate loosely so we can surface
        // those errors instead of failing on the strict validator.
        if ($request->filled('error')) {
            Log::channel('ai')->warning('oauth.callback_provider_error', [
                'error' => (string) $request->query('error'),
                'error_description' => (string) $request->query('error_description'),
                'state_prefix' => substr((string) $request->query('state', ''), 0, 8),
            ]);

            return $this->errorPage(
                'Sign-in cancelled: '.(string) $request->query('error_description', 'unknown error'),
                400,
            );
        }

        $validated = $request->validate([
            'state' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $stateKey = self::STATE_PREFIX.$validated['state'];
        // Use Cache::get + explicit forget-on-success rather than Cache::pull.
        // Chrome's network prefetcher (and `<link rel="prerender">` style
        // speculative requests) can hit this URL before the user's real
        // navigation, and `pull` would have already consumed the state. The
        // OAuth `code` is single-use on Shopify's side, so replay is already
        // bounded — keeping the state cached until token exchange succeeds is
        // safe and rescues the prefetch race.
        $payload = Cache::get($stateKey);
        if (! is_array($payload)) {
            $statePrefix = substr((string) $validated['state'], 0, 8);
            Log::channel('ai')->warning('oauth.state_not_found', [
                'state_prefix' => $statePrefix,
                'reason' => 'cache_miss',
                'note' => 'Either TTL expired (default 600s — extend SHOPIFY_OAUTH_PKCE_TTL) or callback was hit twice and a previous attempt already forgot the state.',
            ]);

            return $this->errorPage(
                'Authentication session expired. Please close this window and click Sign in again from the chat.',
                400,
            );
        }

        $config = $this->discoverOidcConfig((string) $payload['shop_domain']);

        $clientId = (string) config('chatbot.oauth.client_id');
        $clientSecret = (string) config('chatbot.oauth.client_secret');

        $form = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => (string) config('chatbot.oauth.redirect_uri'),
            'code' => $validated['code'],
            'code_verifier' => (string) $payload['verifier'],
        ];

        // Shopify advertises `token_endpoint_auth_methods_supported:
        // ["client_secret_basic"]` for confidential clients — send the secret
        // via HTTP Basic. Public PKCE clients leave the secret blank.
        $request = Http::asForm();
        if ($clientSecret !== '') {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        }

        $tokenResponse = $request->post($config['token_endpoint'], $form);

        if (! $tokenResponse->successful()) {
            Log::channel('ai')->warning('oauth.token_exchange_failed', [
                'session_id' => $payload['session_id'],
                'http_status' => $tokenResponse->status(),
                'error' => $tokenResponse->json('error'),
                'error_description' => $tokenResponse->json('error_description'),
            ]);

            return $this->errorPage('Sign-in failed. Please try again.', 400);
        }

        $tokenPayload = (array) $tokenResponse->json();
        $accessToken = (string) ($tokenPayload['access_token'] ?? '');
        if ($accessToken === '') {
            return $this->errorPage('Sign-in failed (no token returned).', 400);
        }

        $expiresIn = (int) ($tokenPayload['expires_in'] ?? config('chatbot.oauth.token_ttl_seconds', 3600));
        $refreshToken = (string) ($tokenPayload['refresh_token'] ?? '');
        // Shopify Customer Account API issues refresh tokens that live ~14
        // days; `refresh_token_expires_in` (if present) gives the absolute
        // ceiling. Fall back to a 13-day default so we never assume a refresh
        // token outlives Shopify's policy.
        $refreshExpiresIn = (int) ($tokenPayload['refresh_token_expires_in']
            ?? config('chatbot.oauth.refresh_token_ttl_seconds', 13 * 24 * 3600));

        AiCustomerSession::updateOrCreate(
            ['session_id' => $payload['session_id']],
            [
                'customer_access_token' => $accessToken,
                'refresh_token' => $refreshToken !== '' ? $refreshToken : null,
                'expires_at' => now()->addSeconds($expiresIn),
                'refresh_token_expires_at' => $refreshToken !== ''
                    ? now()->addSeconds($refreshExpiresIn)
                    : null,
            ],
        );

        // Token is persisted — drop the PKCE state now that the single-use
        // code has been redeemed. A subsequent callback hit (refresh) would
        // then see a clean cache miss + already-authenticated session.
        Cache::forget($stateKey);

        Log::channel('ai')->info('oauth.token_persisted', [
            'session_id' => $payload['session_id'],
            'expires_in' => $expiresIn,
            'has_refresh_token' => $refreshToken !== '',
            'refresh_token_expires_in' => $refreshToken !== '' ? $refreshExpiresIn : null,
        ]);

        return $this->closePopupPage((string) $payload['session_id']);
    }

    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'uuid'],
        ]);

        $session = AiCustomerSession::query()
            ->where('session_id', $validated['session_id'])
            ->first();

        if ($session === null) {
            return response()->json(['authenticated' => false]);
        }

        // Treat a still-redeemable refresh token as "authenticated" — the
        // tool layer transparently exchanges it on the next MCP call, so the
        // widget can keep the signed-in UI even when the access token has
        // lapsed past its 1-hour window.
        $accessLive = ! $session->isExpired();
        $refreshLive = is_string($session->refresh_token)
            && $session->refresh_token !== ''
            && ($session->refresh_token_expires_at === null
                || $session->refresh_token_expires_at->isFuture());

        if (! $accessLive && ! $refreshLive) {
            return response()->json(['authenticated' => false]);
        }

        return response()->json([
            'authenticated' => true,
            'expires_at' => $session->expires_at->toIso8601String(),
            'refreshable' => $refreshLive,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'uuid'],
        ]);

        $session = AiCustomerSession::query()
            ->where('session_id', $validated['session_id'])
            ->first();

        if ($session !== null) {
            $session->forceFill([
                'customer_access_token' => '',
                'refresh_token' => null,
                'refresh_token_expires_at' => null,
                'expires_at' => now()->subSecond(),
            ])->save();

            Log::channel('ai')->info('oauth.customer_logout', [
                'session_id' => $validated['session_id'],
            ]);
        }

        return response()->json([
            'success' => true,
            'authenticated' => false,
        ]);
    }

    /**
     * @return array{authorization_endpoint: string, token_endpoint: string}
     */
    private function discoverOidcConfig(string $shopDomain): array
    {
        $cacheKey = "ai:oauth:oidc:{$shopDomain}";

        return Cache::remember($cacheKey, $this->chatbotConfig->mcpDiscoveryCacheTtlSeconds(), function () use ($shopDomain): array {
            $url = "https://{$shopDomain}/.well-known/openid-configuration";
            $response = Http::timeout(10)->acceptJson()->get($url);
            abort_unless($response->successful(), 502, 'OIDC discovery failed.');

            $config = (array) $response->json();
            $auth = $config['authorization_endpoint'] ?? null;
            $token = $config['token_endpoint'] ?? null;
            abort_unless(is_string($auth) && is_string($token), 502, 'OIDC discovery payload incomplete.');

            return ['authorization_endpoint' => $auth, 'token_endpoint' => $token];
        });
    }

    private function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function closePopupPage(string $sessionId): Response
    {
        $safeSession = htmlspecialchars($sessionId, ENT_QUOTES);
        $html = <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Sign-in complete</title></head>
<body style="font-family:system-ui;padding:32px;text-align:center">
  <p>Sign-in complete. You can close this window.</p>
  <script>
    (function () {
      try {
        if (window.opener) {
          window.opener.postMessage({ type: 'scs_oauth_done', session_id: '{$safeSession}' }, '*');
        }
      } catch (_) {}
      setTimeout(function () { window.close(); }, 100);
    })();
  </script>
</body></html>
HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function errorPage(string $message, int $status): Response
    {
        $safe = htmlspecialchars($message, ENT_QUOTES);
        $html = <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Sign-in error</title></head>
<body style="font-family:system-ui;padding:32px;text-align:center">
  <h1>Sign-in error</h1>
  <p>{$safe}</p>
  <script>setTimeout(function () { window.close(); }, 1500);</script>
</body></html>
HTML;

        return response($html, $status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
