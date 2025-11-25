<?php

namespace App\Services\Shopify;

use App\Facades\GraphQLLoader;

class ShopifyManager
{
    protected $storefront;
    protected $admin;

    public function __construct(StorefrontService $storefront, AdminService $admin)
    {
        $this->storefront = $storefront;
        $this->admin = $admin;
    }

    public function storefront()
    {
        return $this->storefront;
    }

    public function admin()
    {
        return $this->admin;
    }

    /**
     * Dynamically load GraphQL file + send request
     *
     * @param string $type "storefront" | "admin"
     * @param string $path "products/get_products" (without .graphql)
     */
    public function query(string $type, string $path, array $vars = [])
    {
        // Load GraphQL file (storefront/... or admin/...)
        $query = GraphQLLoader::load("$type/$path");

        // Select API handler
        $service = $type === 'storefront'
            ? $this->storefront
            : $this->admin;

        // Make request
        $response = $service->request($query, $vars);

        // Handle Shopify errors (storefront + admin)
        if (!empty($response['errors'])) {
            $error = $response['errors'];

            // Shopify sometimes returns array, sometimes string key list
            if (is_array($error)) {
                $error = json_encode($error, JSON_UNESCAPED_SLASHES);
            }

            throw new \Exception("Shopify API error: {$error}");
        }

        // Validate response structure
        if (!isset($response['data'])) {
            throw new \Exception("Invalid Shopify API response: missing 'data' field");
        }

        return $response;
    }
}
