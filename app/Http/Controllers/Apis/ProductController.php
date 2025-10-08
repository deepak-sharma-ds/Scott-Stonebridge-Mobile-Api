<?php

namespace App\Http\Controllers\Apis;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;

class ProductController extends Controller
{
    protected $storeUrl;
    protected $accessToken;

    public function __construct()
    {
        $this->storeUrl = env('SHOPIFY_STORE_DOMAIN');
        $this->accessToken = env('SHOPIFY_ACCESS_TOKEN');
    }

    // Helper to send GraphQL requests
    private function shopifyGraphQLRequest(string $query, array $variables = [])
    {
        $url = "https://{$this->storeUrl}/admin/api/2024-07/graphql.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Content-Type' => 'application/json',
        ])->post($url, [
            'query' => $query,
            'variables' => $variables,
        ]);

        if ($response->failed()) {
            abort($response->status(), 'Shopify GraphQL request failed.');
        }

        $body = $response->json();

        if (isset($body['errors'])) {
            abort(500, 'Shopify GraphQL errors: ' . json_encode($body['errors']));
        }

        return $body['data'] ?? null;
    }

    // Get all products with pagination (using GraphQL connection)
    public function getAllProducts(Request $request)
    {
        $limit = (int) $request->get('limit', 20);
        $after = $request->get('after');  // cursor for pagination

        $query = <<<'GRAPHQL'
        query ($limit: Int!, $after: String) {
          products(first: $limit, after: $after) {
            edges {
              cursor
              node {
                id
                title
                handle
                description
                images(first: 1) {
                  edges {
                    node {
                      url
                      altText
                    }
                  }
                }
                variants(first: 1) {
                  edges {
                    node {
                      price
                      sku
                    }
                  }
                }
              }
            }
            pageInfo {
              hasNextPage
            }
          }
        }
        GRAPHQL;

        $variables = [
            'limit' => $limit,
            'after' => $after,
        ];

        $data = $this->shopifyGraphQLRequest($query, $variables);

        $productsData = $data['products'] ?? null;
        if (!$productsData) {
            return response()->json([
                'products' => [],
                'next_cursor' => null,
                'has_more' => false,
            ]);
        }

        $products = array_map(function ($edge) {
            return $edge['node'];
        }, $productsData['edges']);

        $lastCursor = end($productsData['edges'])['cursor'] ?? null;
        $hasMore = $productsData['pageInfo']['hasNextPage'] ?? false;

        return response()->json([
            'products' => $products,
            'next_cursor' => $hasMore ? $lastCursor : null,
            'has_more' => $hasMore,
        ]);
    }

    // Search products by title or other fields
    public function searchProducts(Request $request)
    {
        $queryString = $request->get('query');

        if (!$queryString) {
            return response()->json([
                'error' => 'Query parameter is required.'
            ], 400);
        }

        $limit = (int) $request->get('limit', 20);
        $after = $request->get('after');

        $query = <<<'GRAPHQL'
        query ($queryString: String!, $limit: Int!, $after: String) {
        products(first: $limit, after: $after, query: $queryString) {
            edges {
            cursor
            node {
                id
                title
                handle
                description
                images(first: 1) {
                edges {
                    node {
                    url
                    altText
                    }
                }
                }
                variants(first: 1) {
                edges {
                    node {
                    price
                    sku
                    }
                }
                }
            }
            }
            pageInfo {
            hasNextPage
            }
        }
        }
        GRAPHQL;

        $variables = [
            'queryString' => $queryString,
            'limit' => $limit,
            'after' => $after,
        ];

        if (!$after) {
            unset($variables['after']);
        }

        $data = $this->shopifyGraphQLRequest($query, $variables);

        $productsData = $data['products'] ?? null;
        if (!$productsData) {
            return response()->json([
                'products' => [],
                'next_cursor' => null,
                'has_more' => false,
            ]);
        }

        $products = array_map(function ($edge) {
            return $edge['node'];
        }, $productsData['edges']);

        $lastCursor = end($productsData['edges'])['cursor'] ?? null;
        $hasMore = $productsData['pageInfo']['hasNextPage'] ?? false;

        return response()->json([
            'products' => $products,
            'next_cursor' => $hasMore ? $lastCursor : null,
            'has_more' => $hasMore,
        ]);
    }

    // Get product details by product ID (GraphQL)
    public function getProductDetail($productId)
    {
        // Shopify's GraphQL expects a global ID (gid://shopify/Product/{id})
        // If $productId is numeric, we convert it to gid format:
        if (is_numeric($productId)) {
            $productId = "gid://shopify/Product/{$productId}";
        }

        $query = <<<'GRAPHQL'
        query ($id: ID!) {
          product(id: $id) {
            id
            title
            description
            handle
            images(first: 10) {
              edges {
                node {
                  url
                  altText
                }
              }
            }
            variants(first: 10) {
              edges {
                node {
                  id
                  title
                  price
                  sku
                  availableForSale
                }
              }
            }
            vendor
            productType
          }
        }
        GRAPHQL;

        $variables = ['id' => $productId];

        $data = $this->shopifyGraphQLRequest($query, $variables);

        if (!isset($data['product'])) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        return response()->json($data['product']);
    }
}