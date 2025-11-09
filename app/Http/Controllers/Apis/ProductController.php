<?php

namespace App\Http\Controllers\Apis;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\APIShopifyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
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

  /**
   * Get all categories (collections)
   */
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

  /**
   * Get products by category (collection handle)
   */
  public function getProducts(Request $request)
  {
    try {
      $validator = Validator::make($request->all(), [
        'limit' => 'sometimes|integer|min:1|max:250',
        'after' => 'sometimes|string',
        'collection' => 'sometimes|string', // collection handle
        'sort' => 'sometimes|string|in:newest,oldest,low_price,high_price',
        'tag' => 'sometimes|string',
        'search' => 'sometimes|string',
      ]);
      if ($validator->fails()) {
        return response()->json([
          'status' => 422,
          'message' => 'Validation failed',
          'error' => $validator->errors()
        ], 422);
      }

      $limit = (int) $request->get('limit', 20);
      $after = $request->get('after') ?? null;  // cursor for pagination
      $collectionHandle = $request->get('collection'); // e.g. ?collection=crystals
      $sort = $request->get('sort', 'newest'); // default sort
      $tag = $request->get('tag', null); // optional tag filter
      $searchTerm = $request->get('search'); // optional search term
      $filters = $request->get('filters', []);

      // Build filter query
      $conditions = [];
      if ($tag) $conditions[] = "tag:$tag";
      if ($searchTerm) $conditions[] = "title:*$searchTerm*";

      $filterQuery = implode(" AND ", $conditions);

      // Map sort param to Shopify GraphQL
      $sortMap = [
        'categorywise' => [
          'newest' => ['sortKey' => 'CREATED', 'reverse' => true],
          'oldest' => ['sortKey' => 'CREATED', 'reverse' => false],
          'low_price' => ['sortKey' => 'PRICE', 'reverse' => false],
          'high_price' => ['sortKey' => 'PRICE', 'reverse' => true],
        ],
        'allProducts' => [
          'newest' => ['sortKey' => 'CREATED_AT', 'reverse' => true],
          'oldest' => ['sortKey' => 'CREATED_AT', 'reverse' => false],
          'low_price' => ['sortKey' => 'PRICE', 'reverse' => false],
          'high_price' => ['sortKey' => 'PRICE', 'reverse' => true],
        ]
      ];

      if ($collectionHandle) {
        $sortOptions = $sortMap['categorywise'][$sort] ?? $sortMap['categorywise']['newest'];

        $query = <<<'GRAPHQL'
          query getCollectionProducts(
            $handle: String!, 
            $limit: Int!, 
            $after: String, 
            $sortKey: ProductCollectionSortKeys, 
            $reverse: Boolean
          ) {
            collectionByHandle(handle: $handle) {
              id
              title
              handle
              description
              updatedAt
              image {
                url
                altText
              }

              products(first: $limit, after: $after, sortKey: $sortKey, reverse: $reverse) {
                edges {
                  cursor
                  node {
                    id
                    title
                    handle
                    description
                    descriptionHtml
                    vendor
                    productType
                    tags
                    availableForSale
                    totalInventory
                    publishedAt
                    updatedAt
                    onlineStoreUrl

                    options {
                      id
                      name
                      values
                    }

                    # All images
                    images(first: 250) {
                      edges {
                        node {
                          id
                          url
                          altText
                          width
                          height
                        }
                      }
                    }

                    # All variants
                    variants(first: 250) {
                      edges {
                        node {
                          id
                          title
                          sku
                          availableForSale
                          quantityAvailable
                          weight
                          weightUnit
                          selectedOptions {
                            name
                            value
                          }
                          price {
                            amount
                            currencyCode
                          }
                          compareAtPrice {
                            amount
                            currencyCode
                          }
                          compareAtPriceV2 {
                            amount
                            currencyCode
                          }
                          image {
                            url
                            altText
                          }
                        }
                      }
                    }

                    # Related collections (optional)
                    collections(first: 10) {
                      edges {
                        node {
                          id
                          handle
                          title
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
        $sortOptions = $sortMap['allProducts'][$sort] ?? $sortMap['allProducts']['newest'];

        $query = <<<'GRAPHQL'
          query ($limit: Int!, $after: String, $sortKey: ProductSortKeys, $reverse: Boolean, $query: String) {
            products(first: $limit, after: $after, sortKey: $sortKey, reverse: $reverse, query: $query) {
              edges {
                  cursor
                  node {
                    id
                    title
                    handle
                    description
                    descriptionHtml
                    vendor
                    productType
                    tags
                    availableForSale
                    totalInventory
                    publishedAt
                    updatedAt
                    onlineStoreUrl

                    options {
                      id
                      name
                      values
                    }

                    # All images
                    images(first: 250) {
                      edges {
                        node {
                          id
                          url
                          altText
                          width
                          height
                        }
                      }
                    }

                    # All variants
                    variants(first: 250) {
                      edges {
                        node {
                          id
                          title
                          sku
                          availableForSale
                          quantityAvailable
                          weight
                          weightUnit
                          selectedOptions {
                            name
                            value
                          }
                          price {
                            amount
                            currencyCode
                          }
                          compareAtPrice {
                            amount
                            currencyCode
                          }
                          compareAtPriceV2 {
                            amount
                            currencyCode
                          }
                          image {
                            url
                            altText
                          }
                        }
                      }
                    }

                    # Related collections (optional)
                    collections(first: 10) {
                      edges {
                        node {
                          id
                          handle
                          title
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
          'query'   => $filterQuery, // 👈 use proper query expression
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

      // Filter by availability (optional)
      if (!empty($filters['availability'])) {
        $availability = $filters['availability'];

        $products = array_filter($products, function ($product) use ($availability) {
          if (in_array('in_stock', $availability) && $product['availableForSale']) {
            return true;
          }
          if (in_array('out_of_stock', $availability) && !$product['availableForSale']) {
            return true;
          }
          return false;
        });
      }

      // Reindex array (like ->values())
      $products = array_values($products);


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

  /**
   * Get product details by handle
   */
  public function getProductDetails(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'handle' => 'required|string',
    ]);

    if ($validator->fails()) {
      return response()->json([
        'status' => 422,
        'message' => 'Validation failed',
        'error' => $validator->errors()
      ], 422);
    }

    $productHandle = trim($request->get('handle'));

    try {
      $query = <<<'GRAPHQL'
        query ($handle: String!) {
          productByHandle(handle: $handle) {
            id
            title
            handle
            descriptionHtml
            vendor
            productType
            tags
            images(first: 10) {
              edges {
                node {
                  url
                  altText
                }
              }
            }
            variants(first: 20) {
              edges {
                node {
                  id
                  title
                  sku
                  priceV2 {
                    amount
                    currencyCode
                  }
                  availableForSale
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
        GRAPHQL;

      $variables = ['handle' => $productHandle];

      $data = $this->shopify->storefrontApiRequest($query, $variables);

      if (isset($data['errors'])) {
        return response()->json([
          'status' => 500,
          'message' => 'Failed to fetch product details',
          'errors' => $data['errors'],
        ], 500);
      }

      $product = data_get($data, 'data.productByHandle');
      if (!$product) {
        return response()->json([
          'status' => 404,
          'message' => 'Product not found',
        ], 404);
      }

      return response()->json([
        'status' => 200,
        'message' => 'Product details fetched successfully',
        'data' => $product,
      ], 200);
    } catch (\Throwable $th) {
      return response()->json([
        'status' => 500,
        'message' => 'Failed to fetch product details',
        'error' => $th->getMessage(),
      ], 500);
    }
  }

  /**
   * Get Featured Products by tag (collection handle)
   */
  public function getFeaturedProducts(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'tag' => 'required|string', // collection handle
      'limit' => 'sometimes|integer|min:1|max:250',
      'after' => 'sometimes|string',
    ]);
    if ($validator->fails()) {
      return response()->json([
        'status' => 422,
        'message' => 'Validation failed',
        'error' => $validator->errors()
      ], 422);
    }

    $tag = $request->get('tag'); // e.g. ?tag=featured
    $limit = (int) $request->get('limit', 10);
    $after = $request->get('after') ?? null;  // cursor for pagination

    try {
      $query = <<<'GRAPHQL'
        query getFeaturedProducts($tag: String!, $limit: Int!, $after: String) {
          collectionByHandle(handle: $tag) {
            id
            title
            products(first: $limit, after: $after) {
              edges {
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
            }
          }
        }
        GRAPHQL;

      $variables = [
        'tag' => $tag,
        'limit' => $limit,
        'after' => $after,
      ];

      $data = $this->shopify->storefrontApiRequest($query, $variables);
      dd($data);
      if (isset($data['errors'])) {
        return response()->json([
          'status' => 500,
          'message' => 'Failed to fetch featured products',
          'error' => $data['errors']
        ], 500);
      }

      $collection = data_get($data, 'data.collectionByHandle');
      if (!$collection) {
        return response()->json([
          'status' => 404,
          'message' => 'Collection not found',
          'data' => []
        ], 404);
      }

      $products = collect(data_get($collection, 'products.edges', []))
        ->map(fn($edge) => $edge['node']);

      return response()->json([
        'status' => 200,
        'message' => 'Featured products fetched successfully',
        'data' => $products
      ], 200);
    } catch (\Throwable $th) {
      return response()->json([
        'status' => 500,
        'message' => 'Failed to fetch featured products',
        'error' => $th->getMessage()
      ], 500);
    }
  }
}
