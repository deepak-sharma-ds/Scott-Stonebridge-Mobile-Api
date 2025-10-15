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
    dd($data);
    return response()->json($data['data']['cartCreate']);
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
      dd($th);
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
        mutation cartLinesUpdate($cartId: ID!, $lines: [CartLineUpdateInput!]!) {
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
      //throw $th;
      dd($th);
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
      dd($data);
      return response()->json($data['data']['cartLinesRemove']);
    } catch (\Throwable $th) {
      //throw $th;
      dd($th);
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
      dd($data);
      return response()->json($data['data']['cart']);
    } catch (\Throwable $th) {
      //throw $th;
      dd($th);
    }
  }
}
