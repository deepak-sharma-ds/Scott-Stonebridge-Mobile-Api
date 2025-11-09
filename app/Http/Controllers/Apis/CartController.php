<?php

namespace App\Http\Controllers\Apis;

use App\Http\Controllers\Controller;
use App\Services\APIShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CartController extends Controller
{
  protected $shopify;

  public function __construct()
  {
    $this->shopify = new APIShopifyService;
  }

  public function createCart()
  {
    try {

      $query = <<<'GRAPHQL'
        mutation cartCreate {
          cartCreate {
            cart {
              id
              createdAt
              updatedAt
              lines(first: 10) {
                edges {
                  node {
                    id
                    quantity
                    merchandise {
                      ... on ProductVariant {
                        id
                        title
                        product {
                          title
                          handle
                        }
                        priceV2 {
                          amount
                          currencyCode
                        }
                      }
                    }
                  }
                }
              }
              estimatedCost {
                totalAmount {
                  amount
                  currencyCode
                }
              }
            }
            userErrors {
              code
              field
              message
            }

            warnings {
              code
              message
              target
            }
          }
        }
      GRAPHQL;

      $data = $this->shopify->storefrontApiRequest($query);
      if (isset($data['errors'])) {
        return response()->json([
          'status' => 500,
          'message' => 'Failed to fetch product details',
          'errors' => $data['errors'],
        ], 500);
      }

      $cartCreate = data_get($data, 'data.cartCreate');
      if (!$cartCreate) {
        return response()->json([
          'status' => 404,
          'message' => 'Cart not found',
        ], 404);
      }

      return response()->json([
        'status' => 200,
        'message' => 'Cart created successfully',
        'data' => $cartCreate,
      ], 200);
    } catch (\Throwable $th) {
      return response()->json([
        'status' => 500,
        'message' => 'Something went wrong.',
        'error' => $th->getMessage()
      ], 500);
    }
  }

  public function addToCart(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'cartId' => 'required|string',
      'variantId' => 'required|string',
      'quantity' => 'required|integer|min:1',
    ]);

    if ($validator->fails()) {
      return response()->json(['errors' => $validator->errors()], 422);
    }
    try {
      $query = <<<'GRAPHQL'
        mutation cartLinesAdd($cartId: ID!, $lines: [CartLineInput!]!) {
          cartLinesAdd(cartId: $cartId, lines: $lines) {
            cart {
              id
              lines(first: 10) {
                edges {
                  node {
                    id
                    quantity
                    merchandise {
                      ... on ProductVariant {
                        id
                        title
                        priceV2 {
                          amount
                          currencyCode
                        }
                      }
                    }
                  }
                }
              }
              estimatedCost {
                totalAmount {
                  amount
                  currencyCode
                }
              }
            }
            userErrors {
              field
              message
            }
            warnings {
              code
              message
              target
            }
          }
        }
      GRAPHQL;

      $variables = [
        'cartId' => $request->cartId,
        'lines' => [
          [
            'merchandiseId' => $request->variantId,
            'quantity' => (int) $request->quantity,
          ]
        ]
      ];

      $data = $this->shopify->storefrontApiRequest($query, $variables);
      if (isset($data['errors'])) {
        return response()->json([
          'status' => 500,
          'message' => 'Failed to fetch product details',
          'errors' => $data['errors'],
        ], 500);
      }

      $cartLinesAdd = data_get($data, 'data.cartLinesAdd');
      if (!$cartLinesAdd) {
        return response()->json([
          'status' => 404,
          'message' => 'Cart not found',
        ], 404);
      }

      return response()->json([
        'status' => 200,
        'message' => 'Product has been added in cart successfully',
        'data' => $cartLinesAdd,
      ], 200);
    } catch (\Throwable $th) {
      return response()->json([
        'status' => 500,
        'message' => 'Something went wrong.',
        'error' => $th->getMessage()
      ], 500);
    }
  }

  public function updateToCart(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'cartId' => 'required|string',
      'lineId' => 'required|string',
      'quantity' => 'required|integer|min:1',
    ]);

    if ($validator->fails()) {
      return response()->json(['errors' => $validator->errors()], 422);
    }

    try {
      $query = <<<'GRAPHQL'
        mutation cartLinesUpdate($cartId: ID!, $lines: [CartLineUpdateInput!]!, $currencyCode: CurrencyCode!) {
          @inContext(currency: $currencyCode) {
            cartLinesUpdate(cartId: $cartId, lines: $lines) {
              cart {
                id
                lines(first: 10) {
                  edges {
                    node {
                      id
                      quantity
                    }
                  }
                }
              }
              userErrors {
                field
                message
              }
              warnings {
                code
                message
                target
              }
            }
          }
        }
      GRAPHQL;

      $variables = [
        'cartId' => $request->cartId,
        'lines' => [
          [
            'id' => $request->lineId,
            'quantity' => (int) $request->quantity,
          ]
        ]
      ];

      $data = $this->shopify->storefrontApiRequest($query, $variables);
      if (isset($data['errors'])) {
        return response()->json([
          'status' => 500,
          'message' => 'Failed to fetch product details',
          'errors' => $data['errors'],
        ], 500);
      }

      $cartLinesUpdate = data_get($data, 'data.cartLinesUpdate');
      if (!$cartLinesUpdate) {
        return response()->json([
          'status' => 404,
          'message' => 'Cart not found',
        ], 404);
      }

      return response()->json([
        'status' => 200,
        'message' => 'Product has been updated in cart successfully',
        'data' => $cartLinesUpdate,
      ], 200);
    } catch (\Throwable $th) {
      return response()->json([
        'status' => 500,
        'message' => 'Something went wrong.',
        'error' => $th->getMessage()
      ], 500);
    }
  }

  public function removeToCart(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'cartId' => 'required|string',
      'lineId' => 'required|string',
    ]);
    if ($validator->fails()) {
      return response()->json(['errors' => $validator->errors()], 422);
    }

    try {
      $query = <<<'GRAPHQL'
      mutation cartLinesRemove($cartId: ID!, $lineIds: [ID!]!) {
        cartLinesRemove(cartId: $cartId, lineIds: $lineIds) {
          cart {
            id
            lines(first: 10) {
              edges {
                node {
                  id
                  quantity
                }
              }
            }
          }
          userErrors {
            field
            message
          }
          warnings {
            code
            message
            target
          }
        }
      }
    GRAPHQL;

      $variables = [
        'cartId' => $request->cartId,
        'lineIds' => [$request->lineId],
      ];

      $data = $this->shopify->storefrontApiRequest($query, $variables);
      if (isset($data['errors'])) {
        return response()->json([
          'status' => 500,
          'message' => 'Failed to fetch product details',
          'errors' => $data['errors'],
        ], 500);
      }

      $cartLinesRemove = data_get($data, 'data.cartLinesRemove');
      if (!$cartLinesRemove) {
        return response()->json([
          'status' => 404,
          'message' => 'Cart not found',
        ], 404);
      }

      return response()->json([
        'status' => 200,
        'message' => 'Product has been from cart successfully',
        'data' => $cartLinesRemove,
      ], 200);
    } catch (\Throwable $th) {
      return response()->json([
        'status' => 500,
        'message' => 'Something went wrong.',
        'error' => $th->getMessage()
      ], 500);
    }
  }

  public function getCartDetails(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'cartId' => 'required|string',
    ]);
    if ($validator->fails()) {
      return response()->json(['errors' => $validator->errors()], 422);
    }

    try {
      $query = <<<'GRAPHQL'
        query cartQuery($cartId: ID!) {
          cart(id: $cartId) {
            id
            checkoutUrl
            createdAt
            updatedAt
            lines(first: 20) {
              edges {
                node {
                  id
                  quantity
                  merchandise {
                    ... on ProductVariant {
                      id
                      title
                      product {
                        id
                        title
                        handle
                      }
                      priceV2 {
                        amount
                        currencyCode
                      }
                      compareAtPriceV2  {
                        amount
                        currencyCode
                      }
                    }
                  }
                }
              }
            }
            estimatedCost {
              subtotalAmount {
                amount
                currencyCode
              }
              totalAmount {
                amount
                currencyCode
              }
            }
          }
        }
      GRAPHQL;

      $variables = [
        'cartId' => $request->cartId,
      ];

      $data = $this->shopify->storefrontApiRequest($query, $variables);
      if (isset($data['errors'])) {
        return response()->json([
          'status' => 500,
          'message' => 'Failed to fetch product details',
          'errors' => $data['errors'],
        ], 500);
      }

      $cart = data_get($data, 'data.cart');
      if (!$cart) {
        return response()->json([
          'status' => 404,
          'message' => 'Cart not found',
        ], 404);
      }

      $formattedCart = [
        'id' => $cart['id'],
        'checkout_url' => $cart['checkoutUrl'],
        'created_at' => $cart['createdAt'],
        'updated_at' => $cart['updatedAt'],
        'subtotal' => $cart['estimatedCost']['subtotalAmount']['amount'],
        'subtotal_currency' => $cart['estimatedCost']['subtotalAmount']['currencyCode'],
        'total' => $cart['estimatedCost']['totalAmount']['amount'],
        'total_currency' => $cart['estimatedCost']['totalAmount']['currencyCode'],
        'items' => collect($cart['lines']['edges'] ?? [])->map(function ($edge) {
          $node = $edge['node'];
          $variant = $node['merchandise'];

          return [
            'line_id' => $node['id'],
            'quantity' => $node['quantity'],
            'variant_id' => $variant['id'],
            'variant_title' => $variant['title'],
            'product' => [
              'id' => $variant['product']['id'],
              'title' => $variant['product']['title'],
              'handle' => $variant['product']['handle'],
            ],
            'price' => $variant['priceV2']['amount'],
            'compare_at_price' => $variant['compareAtPriceV2']['amount'] ?? null,
            'currency' => $variant['priceV2']['currencyCode'],
            'discount_amount' => isset($variant['compareAtPriceV2'])
              ? round(max(0, (float)$variant['compareAtPriceV2']['amount'] - (float)$variant['priceV2']['amount']))
              : 0,
          ];
        })->values(),
      ];

      return response()->json([
        'status' => 200,
        'message' => 'Cart details fetched successfully',
        'data' => $formattedCart,
        // 'data' => $cart,
      ], 200);
    } catch (\Throwable $th) {
      return response()->json([
        'status' => 500,
        'message' => 'Something went wrong.',
        'error' => $th->getMessage()
      ], 500);
    }
  }

  public function cartBuyerIdentityUpdate(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'cartId' => 'required|string',
      // 'userId' => 'required|string',
    ]);

    if ($validator->fails()) {
      return response()->json(['errors' => $validator->errors()], 422);
    }
    try {
      $query = <<<'GRAPHQL'
        mutation cartBuyerIdentityUpdate($cartId: ID!, $buyerIdentity: CartBuyerIdentityInput!) {
          cartBuyerIdentityUpdate(cartId: $cartId, buyerIdentity: $buyerIdentity) {
            cart {
              id
              createdAt
              updatedAt
              buyerIdentity {
                email
                customer {
                  id
                  firstName
                  lastName
                  phone
                  acceptsMarketing
                  tags
                }
              }
            }
            userErrors {
              field
              message
            }
            warnings {
              code
              message
              target
            }
          }
        }
      GRAPHQL;

      $variables = [
        'cartId' => $request->cartId, // your cart ID
        'buyerIdentity' => [
          // Logged in user email from token from middleware
          'email' => $request->shopify_customer_data['email'],

          // Optional country code for tax/shipping calculations
          // 'countryCode' => 'US',

          // Optional phone
          // 'phone' => '+11234567890',
        ],
      ];


      $data = $this->shopify->storefrontApiRequest($query, $variables);
      if (isset($data['errors'])) {
        return response()->json([
          'status' => 500,
          'message' => 'Failed to attach user to cart',
          'errors' => $data['errors'],
        ], 500);
      }

      $cartBuyerIdentityUpdate = data_get($data, 'data.cartBuyerIdentityUpdate');
      if (!$cartBuyerIdentityUpdate) {
        return response()->json([
          'status' => 404,
          'message' => 'Cart not found',
        ], 404);
      }

      return response()->json([
        'status' => 200,
        'message' => 'User has been attached with cart successfully',
        'data' => $cartBuyerIdentityUpdate,
      ], 200);
    } catch (\Throwable $th) {
      return response()->json([
        'status' => 500,
        'message' => 'Something went wrong.',
        'error' => $th->getMessage()
      ], 500);
    }
  }
}
