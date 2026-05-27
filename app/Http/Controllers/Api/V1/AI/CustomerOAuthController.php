<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\AI;

use App\Models\AiCustomerSession;
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

    private const DISCOVERY_CACHE_TTL = 3600;

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

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => (string) config('chatbot.oauth.client_id'),
            'redirect_uri' => (string) config('chatbot.oauth.redirect_uri'),
            'scope' => implode(' ', (array) config('chatbot.oauth.scopes', [])),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return redirect()->away($config['authorization_endpoint'].'?'.$query);
    }

    public function callback(Request $request): Response
    {
        $validated = $request->validate([
            'state' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $stateKey = self::STATE_PREFIX.$validated['state'];
        $payload = Cache::pull($stateKey);
        if (! is_array($payload)) {
            return $this->errorPage('Authentication session expired. Please try again.', 400);
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

        AiCustomerSession::updateOrCreate(
            ['session_id' => $payload['session_id']],
            [
                'customer_access_token' => $accessToken,
                'expires_at' => now()->addSeconds($expiresIn),
            ],
        );

        Log::channel('ai')->info('oauth.token_persisted', [
            'session_id' => $payload['session_id'],
            'expires_in' => $expiresIn,
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
            ->active()
            ->first();

        if ($session === null) {
            return response()->json(['authenticated' => false]);
        }

        return response()->json([
            'authenticated' => true,
            'expires_at' => $session->expires_at->toIso8601String(),
        ]);
    }

    /**
     * @return array{authorization_endpoint: string, token_endpoint: string}
     */
    private function discoverOidcConfig(string $shopDomain): array
    {
        $cacheKey = "ai:oauth:oidc:{$shopDomain}";

        return Cache::remember($cacheKey, self::DISCOVERY_CACHE_TTL, function () use ($shopDomain): array {
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

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
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

        return response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
