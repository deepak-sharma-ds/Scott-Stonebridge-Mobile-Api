<?php

namespace App\Http\Controllers\Apis;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\APIShopifyService;
use App\Facades\Shopify;
use App\Traits\ShopifyResponseFormatter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
  use ShopifyResponseFormatter;

  protected $storeUrl;
  protected $accessToken;
  protected $shopify;

  public function __construct(APIShopifyService $shopify)
  {
    $this->storeUrl = config('shopify.store_domain');
    $this->accessToken = config('shopify.access_token');
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
    try {
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
                  descriptionHtml
                  description
                  vendor
                  productType
                  tags
                  options {
                    id
                    name
                    values
                  }
                  images(first: 250) {
                    edges {
                        node {
                          url
                          altText
                        }
                    }
                  }
                  variants(first: 250) {
                    edges {
                        node {
                        id
                        title
                        sku
                        availableForSale
                        price
                        compareAtPrice
                        image {
                          url
                          altText
                        }
                        selectedOptions {
                          name
                          value
                        }
                      }
                    }
                  }
                  options {
                    name
                    values
                  }
                  createdAt
                  updatedAt
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
    } catch (\Throwable $th) {
      //throw $th;
      dd($th->getMessage());
    }
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

    $products = array_map(function ($edge) {
      $product = $edge['node'];

      $product['variants'] = array_map(function ($variantEdge) {
        $variant = $variantEdge['node'];

        // ---- Inventory aggregation
        $totalAvailable = 0;

        foreach (
          $variant['inventoryItem']['inventoryLevels']['edges'] ?? []
          as $level
        ) {
          foreach ($level['node']['quantities'] as $qty) {
            if ($qty['name'] === 'available') {
              $totalAvailable += (int) $qty['quantity'];
            }
          }
        }

        // ---- Weight normalization (to grams)
        $weight = $variant['inventoryItem']['measurement']['weight'] ?? null;
        $weightGrams = 0;

        if ($weight) {
          $weightGrams = match ($weight['unit']) {
            'POUNDS' => $weight['value'] * 453.592,
            'OUNCES' => $weight['value'] * 28.3495,
            'KILOGRAMS' => $weight['value'] * 1000,
            default => $weight['value'],
          };
        }

        return [
          'id' => $variant['id'],
          'title' => $variant['title'],
          'sku' => $variant['sku'],
          'price' => (float) $variant['price'],
          'compare_at_price' => $variant['compareAtPrice']
            ? (float) $variant['compareAtPrice']
            : null,
          'available_for_sale' => $variant['availableForSale'],
          'total_available' => $totalAvailable,
          'in_stock' => $totalAvailable > 0,
          'weight_grams' => (int) round($weightGrams),
          'image' => $variant['image']['url'] ?? null,
          'options' => collect($variant['selectedOptions'])
            ->pluck('value', 'name')
            ->toArray(),
        ];
      }, $product['variants']['edges']);

      return $product;
    }, $productsData['edges']);

    return response()->json($data['product']);
  }

  /**
   * Get all categories (collections)
   */
  public function getCategories(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'limit' => 'sometimes|integer|min:1|max:250',
      'after' => 'sometimes|string|nullable',
    ]);
    if ($validator->fails()) {
      return $this->fail('Validation error.', $validator->errors());
    }

    try {
      $vars = [
        'limit' => (int) $request->get('limit', 20),
        'after' => $request->get('after'),
      ];

      $response = Shopify::query(
        'storefront',
        'collections/get_collections',
        $vars
      );

      // Pass the MAIN "data" object, not the inner node
      $parsed = $this->parseEdges(
        data_get($response, 'data'),   // ← IMPORTANT
        'collections'
      );

      return $this->success('Collections fetched successfully', $parsed);
    } catch (\Throwable $e) {
      return $this->fail('Failed to fetch collections', $e->getMessage());
    }
  }


  /**
   * Get products (optionally by collection)
   */
  public function getProducts(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'limit'      => 'sometimes|integer|min:1|max:250',
      'after'      => 'sometimes|string|nullable',
      'collection' => 'sometimes|string|nullable',
      'sort'       => 'sometimes|string|in:newest,oldest,low_price,high_price',
      'tag'        => 'sometimes|string|nullable',
      'search'     => 'sometimes|string|nullable',
      'filters'    => 'sometimes|array',
    ]);
    if ($validator->fails()) {
      return $this->fail('Validation error.', $validator->errors());
    }

    try {
      $limit  = (int) $request->get('limit', 20);
      $after  = $request->get('after');
      $handle = $request->get('collection');     // collection handle
      $sort   = $request->get('sort', 'newest');
      $tag    = $request->get('tag');
      $search = $request->get('search');
      $filters = $request->get('filters', []);

      //----------------------------------------------------------------------
      // Build Query Filter (Shopify Search Query)
      //----------------------------------------------------------------------
      $conditions = [];
      if ($tag)       $conditions[] = "tag:$tag";
      if ($search)    $conditions[] = "title:*$search*";
      $filterQuery = implode(" AND ", $conditions);

      //----------------------------------------------------------------------
      // Sort Options Mapping
      //----------------------------------------------------------------------
      $sortMap = [
        'categorywise' => [
          'newest'     => ['sortKey' => 'CREATED',     'reverse' => true],
          'oldest'     => ['sortKey' => 'CREATED',     'reverse' => false],
          'low_price'  => ['sortKey' => 'PRICE',       'reverse' => false],
          'high_price' => ['sortKey' => 'PRICE',       'reverse' => true],
        ],
        'allProducts' => [
          'newest'     => ['sortKey' => 'CREATED_AT',  'reverse' => true],
          'oldest'     => ['sortKey' => 'CREATED_AT',  'reverse' => false],
          'low_price'  => ['sortKey' => 'PRICE',       'reverse' => false],
          'high_price' => ['sortKey' => 'PRICE',       'reverse' => true],
        ]
      ];

      //----------------------------------------------------------------------
      // If collection handle exists → use collection endpoint
      //----------------------------------------------------------------------
      if ($handle) {

        $sortOptions = $sortMap['categorywise'][$sort];

        $vars = [
          'handle'  => $handle,
          'limit'   => $limit,
          'after'   => $after,
          'sortKey' => $sortOptions['sortKey'],
          'reverse' => $sortOptions['reverse'],
        ];

        $response = Shopify::query(
          'storefront',
          'products/get_products_by_collection',
          $vars
        );

        $collection = data_get($response, 'data.collectionByHandle');

        if (!$collection) {
          return $this->success('Collection not found', [
            'products' => [],
            'next_cursor' => null,
            'has_more' => false,
          ]);
        }

        $parsed = $this->parseConnection(
          data_get($collection, 'products'),
          'products'
        );

        $parsed['products'] = $this->refineNestedEdges($parsed['products']);
      }

      //----------------------------------------------------------------------
      // Otherwise fetch all products
      //----------------------------------------------------------------------
      else {

        $sortOptions = $sortMap['allProducts'][$sort];

        $vars = [
          'limit'   => $limit,
          'after'   => $after,
          'sortKey' => $sortOptions['sortKey'],
          'reverse' => $sortOptions['reverse'],
          'query'   => $filterQuery ?: null,
        ];

        $response = Shopify::query(
          'storefront',
          'products/get_all_products',
          $vars
        );

        $parsed = $this->parseConnection(
          data_get($response, 'data.products'),
          'products'
        );

        $parsed['products'] = $this->refineNestedEdges($parsed['products']);
      }

      //----------------------------------------------------------------------
      // Post-filter: availability (local filter)
      //----------------------------------------------------------------------
      if (!empty($filters['availability'])) {
        $availability = $filters['availability'];

        $parsed['products'] = array_values(array_filter($parsed['products'], function ($p) use ($availability) {
          if (in_array('in_stock', $availability) && $p['availableForSale']) return true;
          if (in_array('out_of_stock', $availability) && !$p['availableForSale']) return true;
          return false;
        }));
      }

      //----------------------------------------------------------------------
      // Final Response
      //----------------------------------------------------------------------
      return $this->success('Products fetched successfully', $parsed);
    } catch (\Throwable $e) {
      return $this->fail('Failed to fetch products', $e->getMessage());
    }
  }


  /**
   * Get product details by handle
   */
  public function getProductDetails(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'handle' => 'required|string',
    ]);
    if ($validator->fails()) {
      return $this->fail('Validation error.', $validator->errors());
    }

    try {
      $vars = [
        'handle' => trim($request->get('handle')),
      ];

      // Call GraphQL file using new structure
      $response = Shopify::query(
        'storefront',
        'products/get_product_details',
        $vars
      );

      $product = data_get($response, 'data.productByHandle');

      if (!$product) {
        return $this->fail('Product not found');
      }

      // Dynamic recursive refinement (images, variants, collections, etc.)
      $product = $this->refineNestedEdges($product);

      return $this->success('Product details fetched successfully', $product);
    } catch (\Throwable $e) {
      return $this->fail('Failed to fetch product details', $e->getMessage());
    }
  }

  /**
   * Get Featured Products by tag (collection handle)
   */
  public function getFeaturedProducts(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'tag'   => 'required|string',
      'limit' => 'sometimes|integer|min:1|max:250',
      'after' => 'sometimes|string|nullable',
    ]);
    if ($validator->fails()) {
      return $this->fail('Validation error.', $validator->errors());
    }

    try {
      $vars = [
        'tag'   => $request->get('tag'),
        'limit' => (int) $request->get('limit', 10),
        'after' => $request->get('after'),
      ];

      // ----------------------------------------------
      // Shopify Query using new structure
      // ----------------------------------------------
      $response = Shopify::query(
        'storefront',
        'products/get_featured_products',
        $vars
      );

      $collection = data_get($response, 'data.collectionByHandle');

      if (!$collection) {
        return $this->success('Collection not found', [
          'products'     => [],
          'next_cursor'  => null,
          'has_more'     => false,
        ]);
      }

      // ----------------------------------------------
      // Parse Top-Level Pagination (products)
      // ----------------------------------------------
      $parsed = $this->parseConnection(
        data_get($collection, 'products'),
        'products'
      );

      // ----------------------------------------------
      // Refine Nested Edges (images, variants, etc.)
      // ----------------------------------------------
      $parsed['products'] = $this->refineNestedEdges($parsed['products']);

      return $this->success('Featured products fetched successfully', $parsed);
    } catch (\Throwable $e) {
      return $this->fail('Failed to fetch featured products', $e->getMessage());
    }
  }
}
