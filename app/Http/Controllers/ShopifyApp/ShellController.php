<?php

namespace App\Http\Controllers\ShopifyApp;

use App\Http\Controllers\Controller;
use App\Services\Bundles\ShopifyBundleCredentials;

/**
 * Serves the embedded app (React + Polaris) built from shopify-bundle-app/web.
 * All client-side routes under /shopify-app/* return this page.
 */
class ShellController extends Controller
{
    public const BUILD_DIR = 'shopify-app-build';

    public function __invoke(ShopifyBundleCredentials $credentials)
    {
        $manifestPath = public_path(self::BUILD_DIR . '/.vite/manifest.json');
        $entry = null;

        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
            $entry = collect($manifest)->first(fn ($chunk) => $chunk['isEntry'] ?? false);
        }

        return response()->view('shopify-app.shell', [
            'apiKey' => $credentials->clientId(),
            'assets' => '/' . self::BUILD_DIR,
            'entry'  => $entry,
        ]);
    }
}
