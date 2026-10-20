<?php

namespace App\Services\Bundles;

/**
 * Helpers for Shopify global IDs (gid://shopify/Product/123).
 */
class Gid
{
    public static function make(string $type, int|string $id): string
    {
        return str_starts_with((string) $id, 'gid://') ? (string) $id : "gid://shopify/{$type}/{$id}";
    }

    public static function toId(?string $gid): ?string
    {
        if ($gid === null || $gid === '') {
            return null;
        }

        return substr($gid, strrpos($gid, '/') + 1);
    }
}
