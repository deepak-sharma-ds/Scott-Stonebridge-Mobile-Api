<?php

namespace App\Console\Commands;

use App\Models\Store;
use Illuminate\Console\Command;

/**
 * Point the bundle app's credentials at a given Shopify store (creates the Store row if it
 * doesn't exist yet), so the embedded app's session-token check accepts that store's tokens.
 *
 * Same Shopify app (client ID/secret) can be installed on several stores; this app is
 * single-tenant (one active store at a time), picked by config('bundles.store_id').
 */
class UseBundleStore extends Command
{
    protected $signature = 'bundles:use-store
        {domain : Shop domain, e.g. client-store.myshopify.com}
        {--name= : Friendly name shown in the admin dashboard}
        {--token= : Static Admin API access token (optional; omit to use the client credentials grant)}';

    protected $description = 'Create/update a Store row for a shop and show how to make it the active one';

    public function handle(): int
    {
        $domain = strtolower(preg_replace('#^https?://#i', '', rtrim((string) $this->argument('domain'), '/')));

        if (! str_ends_with($domain, '.myshopify.com')) {
            $this->warn("'{$domain}' doesn't look like a *.myshopify.com domain — double-check it.");
        }

        $store = Store::firstOrNew(['domain' => $domain]);
        $store->name = $this->option('name') ?: ($store->name ?: $domain);
        $store->app_client_id = $store->app_client_id ?: config('bundles.client_id');
        $store->app_secret_key = $store->app_secret_key ?: config('bundles.client_secret');
        if ($token = $this->option('token')) {
            $store->app_admin_access_token = $token;
        }
        $store->status = true;
        $store->save();

        $this->info("Store #{$store->id} ready: {$domain}");

        if (! $store->app_client_id || ! $store->app_secret_key) {
            $this->error('No client ID/secret on the row and none in config/bundles.php (BUNDLE_APP_CLIENT_ID/SECRET) — set one before switching.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('To make this the active store:');
        $this->line("  1. In .env set:  BUNDLE_STORE_ID={$store->id}");
        $this->line('  2. php artisan config:clear');
        $this->line('  3. Reload the embedded app in that store\'s Shopify admin.');

        return self::SUCCESS;
    }
}
