<?php

namespace App\Services\GraphQL;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

class GraphQLLoaderService
{
    protected string $disk = 'graphql';
    protected bool $cacheEnabled;
    protected int $cacheMinutes;

    public function __construct()
    {
        // Enable cache except in local; you can change logic as needed
        $this->cacheEnabled = !app()->environment('local');
        $this->cacheMinutes = (int) config('shopify.graphql.cache_minutes', 1440);
    }

    /**
     * Load GraphQL query file from disk.
     */
    public function load(string $path): string
    {
        $filePath = $this->normalizePath($path);

        // Cache key uses the normalized path (safe string)
        $cacheKey = "graphql_query_{$filePath}";

        if ($this->cacheEnabled) {
            $content = Cache::remember($cacheKey, $this->cacheMinutes, function () use ($filePath) {
                return $this->readFile($filePath);
            });
        } else {
            $content = $this->readFile($filePath);
        }

        // Point 4: verify checksum if enabled in config
        if (config('shopify.graphql.verify_hashes', false)) {
            $this->verifyChecksum($filePath, $content);
        }

        return $content;
    }

    /**
     * Read the GraphQL file
     */
    protected function readFile(string $filePath): string
    {
        if (!Storage::disk($this->disk)->exists($filePath)) {
            throw new Exception("GraphQL file not found: {$filePath}");
        }

        $content = Storage::disk($this->disk)->get($filePath);

        if ($content === false || trim($content) === '') {
            throw new Exception("GraphQL file is empty: {$filePath}");
        }

        // Point 3: Basic GraphQL sanity check:
        $trimmed = ltrim($content);
        $starts = strtolower(Str::substr($trimmed, 0, 8));
        if (!Str::startsWith($trimmed, ['query', 'mutation', 'subscription', 'fragment', '{'])) {
            // allow fragments or shorthand queries starting with '{'
            throw new Exception("Invalid GraphQL content in file: {$filePath}");
        }

        return $content;
    }

    /**
     * Normalize input path
     * Examples:
     *  "storefront/products/get_products"
     *  "admin/orders/create_order"
     */
    protected function normalizePath(string $path): string
    {
        // remove leading/trailing slashes and ensure .graphql extension is not duplicated
        $path = trim($path, '/');
        $path = preg_replace('/\.graphql$/i', '', $path);

        // Reject any traversal patterns
        if (str_contains($path, '..')) {
            throw new Exception("Invalid GraphQL query path: path traversal detected.");
        }

        // Allow only letters, numbers, dash, underscore and slashes
        if (!preg_match('/^[a-zA-Z0-9\/_-]+$/', $path)) {
            throw new Exception("Invalid characters in GraphQL path.");
        }

        // Enforce allowed namespaces (Point 2)
        $segments = explode('/', $path);
        $allowedTopLevel = ['storefront', 'admin'];

        if (!in_array($segments[0] ?? '', $allowedTopLevel, true)) {
            throw new Exception("Access to GraphQL namespace forbidden: {$segments[0]}");
        }

        // Return normalized path with extension
        return $path . '.graphql';
    }

    /**
     * Compute checksum for content (sha1)
     */
    protected function computeChecksum(string $content): string
    {
        return sha1($content);
    }

    /**
     * Verify checksum against store (Point 4).
     *
     * By default this reads expected checksums from config('shopify.graphql.checksum_store')
     * which points to a JSON file. You can change to DB or cache as preferred.
     */
    protected function verifyChecksum(string $filePath, string $content): void
    {
        // Load expected checksums from JSON file (if present)
        $storePath = config('shopify.graphql.checksum_store');

        $expected = null;
        if ($storePath && file_exists($storePath)) {
            $json = @file_get_contents($storePath);
            $map = $json ? json_decode($json, true) : null;
            if (is_array($map) && array_key_exists($filePath, $map)) {
                $expected = $map[$filePath];
            }
        }

        // fallback: check Cache for pre-seeded value
        if (!$expected && Cache::has("graphql_checksum_{$filePath}")) {
            $expected = Cache::get("graphql_checksum_{$filePath}");
        }

        // If no expected is found, behave permissively (or throw - your choice)
        if (!$expected) {
            // Option A: throw to force strict pre-seeding
            // throw new Exception("No expected checksum found for {$filePath}");

            // Option B (safer default): log a warning and continue
            // logger()->warning("GraphQL checksum not found for {$filePath}");
            return;
        }

        $actual = $this->computeChecksum($content);
        if (!hash_equals($expected, $actual)) {
            logger()->error("Tampered GraphQL detected: {$filePath}");
            throw new Exception("GraphQL file checksum mismatch: {$filePath}");
        }
    }

    /**
     * Disable caching (useful for development)
     */
    public function disableCache(): self
    {
        $this->cacheEnabled = false;
        return $this;
    }

    /**
     * Force reload a specific file
     */
    /**
     * Force refresh a specific file (clears cache and reloads)
     */
    public function refresh(string $path): string
    {
        $filePath = $this->normalizePath($path);
        $cacheKey = "graphql_query_{$filePath}";
        Cache::forget($cacheKey);
        return $this->load($path);
    }
}
