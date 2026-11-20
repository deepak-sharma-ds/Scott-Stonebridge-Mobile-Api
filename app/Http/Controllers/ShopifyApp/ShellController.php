<?php

namespace App\Http\Controllers\ShopifyApp;

use App\Http\Controllers\Controller;
use App\Services\Bundles\ShopifyBundleCredentials;
use App\Services\Bundles\ShopifyInstallService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Serves the embedded app (React + Polaris) built from shopify-bundle-app/web.
 * All client-side routes under /shopify-app/* return this page.
 */
class ShellController extends Controller
{
    public const BUILD_DIR = 'shopify-app-build';

    public function __invoke(Request $request, ShopifyBundleCredentials $credentials, ShopifyInstallService $install)
    {
        if ($redirect = $this->handleInstall($request, $install)) {
            return $redirect;
        }

        $manifestPath = public_path(self::BUILD_DIR.'/.vite/manifest.json');
        $entry = null;

        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
            $entry = collect($manifest)->first(fn ($chunk) => $chunk['isEntry'] ?? false);
        }

        return response()->view('shopify-app.shell', [
            'apiKey' => $credentials->clientId(),
            'assets' => '/'.self::BUILD_DIR,
            'entry' => $entry,
        ]);
    }

    /**
     * Saves the offline access token when Shopify opens the app right after installation.
     * Returns a redirect when the request must continue elsewhere, otherwise null.
     */
    protected function handleInstall(Request $request, ShopifyInstallService $install): ?RedirectResponse
    {
        $shop = $install->normalizeShop($request->query('shop'));

        try {
            // Authorization code grant callback (redirect URL).
            if ($shop && $request->filled('code') && $request->filled('hmac')) {
                if ($install->installFromAuthorizationCode($request->query())) {
                    $handle = Str::before($shop, '.myshopify.com');

                    return redirect()->away("https://admin.shopify.com/store/{$handle}/apps/".$install->credentials()->clientId());
                }

                Log::warning('Shopify install callback rejected', ['shop' => $shop]);

                return null;
            }

            // Managed install: App Bridge session token on first load.
            if ($request->filled('id_token')) {
                $install->installFromSessionToken((string) $request->query('id_token'));

                return null;
            }

            // Install request signed by Shopify with no token stored yet: start the authorization code grant.
            if ($shop && $request->filled('hmac') && ! $install->hasToken($shop) && $install->isValidHmac($request->query())) {
                return redirect()->away($install->authorizationUrl($shop, url('/shopify-app')));
            }
        } catch (Throwable $e) {
            Log::error('Shopify install: could not store access token', ['shop' => $shop, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
