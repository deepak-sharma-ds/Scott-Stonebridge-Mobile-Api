<?php

namespace App\Http\Controllers\Apis;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\APIShopifyService;
use Illuminate\Support\Facades\Http;

class ProductController extends Controller
{
  protected $storeUrl;
  protected $accessToken;
  protected $shopify;

  public function __construct(APIShopifyService $shopify)
  {
    $this->storeUrl = env('SHOPIFY_STORE_DOMAIN');
    $this->accessToken = env('SHOPIFY_ACCESS_TOKEN');
    $this->shopify = $shopify;
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
    $after = $request->get('after') ?? null;  // cursor for pagination

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

  // Get all categories
  public function getCategories(Request $request)
  {
    $limit = (int) $request->get('limit', 20);
    $after = $request->get('after') ?? null;  // cursor for pagination

    $query = <<<'GRAPHQL'
      query ($limit: Int!, $after: String) {
        collections(first: $limit, after: $after) {
          edges {
            node {
              id
              title
              handle
              image {
                originalSrc
                altText
              }
            }
          }
          pageInfo {
            hasNextPage
            endCursor
          }
        }
      }
      GRAPHQL;

    $variables = [
      'limit' => $limit, // number of collections to fetch
      'after' => $after, // cursor for pagination; null for first page
    ];

    $data = $this->shopify->storefrontApiRequest($query, $variables);
    if (isset($data['errors'])) {
      return response()->json(['error' => 'Failed to fetch collections'], 500);
    }

    if (!$data || !isset($data['data']['collections'])) {
      return response()->json(['error' => 'Failed to fetch collections'], 500);
    }
    $collections = collect(data_get($data, 'data.collections.edges', []))
      ->map(fn($edge) => $edge['node']);
    $lastCursor = data_get($data, 'data.collections.pageInfo.hasNextPage') ? data_get($data, 'data.collections.pageInfo.endCursor') : null;
    $hasMore = data_get($data, 'data.collections.pageInfo.hasNextPage', false);

    return response()->json([
      'status' => 200,
      'message' => 'Collections fetched successfully',
      'data' => [
        'collections' => $collections,
        'next_cursor' => $lastCursor,
        'has_more' => $hasMore,
      ]
    ], 200);
  }

  // Get products by category (collection handle)
  public function getProducts(Request $request)
  {
    try {
      $limit = (int) $request->get('limit', 20);
      $after = $request->get('after') ?? null;  // cursor for pagination
      $collectionHandle = $request->get('collection'); // e.g. ?collection=crystals
      $sort = $request->get('sort', 'newest'); // default sort

      // Map sort param to Shopify GraphQL
      $sortMap = [
        'newest' => ['sortKey' => 'CREATED_AT', 'reverse' => true],
        'oldest' => ['sortKey' => 'CREATED_AT', 'reverse' => false],
        'low_price' => ['sortKey' => 'PRICE', 'reverse' => false],
        'high_price' => ['sortKey' => 'PRICE', 'reverse' => true],
      ];

      $sortOptions = $sortMap[$sort] ?? $sortMap['newest'];

      if ($collectionHandle) {
        $query = <<<'GRAPHQL'
        query ($handle: String!, $limit: Int!, $after: String, $sortKey: ProductSortKeys, $reverse: Boolean) {
          collectionByHandle(handle: $handle) {
            id
            title
            handle
            products(first: $limit, after: $after, sortKey: $sortKey, reverse: $reverse) {
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
                        price {
                          amount
                          currencyCode
                        }
                        sku
                      }
                    }
                  }
                }
              }
              pageInfo {
                hasNextPage
                endCursor
              }
            }
          }
        }
        GRAPHQL;

        $variables = [
          'handle' => $collectionHandle,
          'limit' => $limit,
          'after' => $after,
          'sortKey' => $sortOptions['sortKey'],
          'reverse' => $sortOptions['reverse'],
        ];
        $data = $this->shopify->storefrontApiRequest($query, $variables);
        if (isset($data['errors'])) {
          return response()->json([
            'status' => 500,
            'message' => 'Failed to fetch collections',
          ], 500);
        }
        $collection = data_get($data, 'data.collectionByHandle');
        if (!$collection) {
          return response()->json([
            'products' => [],
            'next_cursor' => null,
            'has_more' => false,
            'message' => 'Collection not found'
          ]);
        }

        $productsData = data_get($collection, 'products', []);
      } else {
        $query = <<<'GRAPHQL'
        query ($limit: Int!, $after: String, $sortKey: ProductSortKeys, $reverse: Boolean) {
          products(first: $limit, after: $after, sortKey: $sortKey, reverse: $reverse) {
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
                      price {
                        amount
                        currencyCode
                      }
                      sku
                    }
                  }
                }
              }
            }
            pageInfo {
              hasNextPage
              endCursor
            }
          }
        }
        GRAPHQL;

        $variables = [
          'limit' => $limit,
          'after' => $after,
          'sortKey' => $sortOptions['sortKey'],
          'reverse' => $sortOptions['reverse'],
        ];
        $data = $this->shopify->storefrontApiRequest($query, $variables);
        if (isset($data['errors'])) {
          return response()->json([
            'status' => 500,
            'message' => 'Failed to fetch products',
          ], 500);
        }

        $productsData = data_get($data, 'data.products', []);
      }


      // Normalize product nodes
      $edges = data_get($productsData, 'edges', []);
      $products = array_map(fn($edge) => $edge['node'], $edges);
      $lastCursor = end($edges)['cursor'] ?? null;
      $hasMore = data_get($productsData, 'pageInfo.hasNextPage', false);

      return response()->json([
        'status' => 200,
        'message' => 'Products fetched successfully',
        'data' => [
          'products' => $products,
          'next_cursor' => $hasMore ? $lastCursor : null,
          'has_more' => $hasMore,
        ]
      ], 200);
    } catch (\Throwable $th) {
      return response()->json([
        'status' => 500,
        'message' => 'Failed to fetch products',
        'error' => $th->getMessage()
      ], 500);
    }
  }
}
