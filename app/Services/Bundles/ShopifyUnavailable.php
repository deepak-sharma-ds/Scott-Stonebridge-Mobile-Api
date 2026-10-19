<?php

namespace App\Services\Bundles;

use RuntimeException;

/**
 * Shopify couldn't be reached for a check that must not be skipped (e.g. validating MAP entries).
 */
class ShopifyUnavailable extends RuntimeException
{
}
