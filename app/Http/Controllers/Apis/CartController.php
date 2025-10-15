<?php

namespace App\Http\Controllers\Apis;

use App\Http\Controllers\Controller;
use App\Services\APIShopifyService;
use Illuminate\Http\Request;

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
                field
                message
              }
            }
          }
        GRAPHQL;

    $data = $this->shopify->storefrontApiRequest($query);
    return response()->json($data['data']['cartCreate']);
  }

  public function addToCart(Request $request)
  {
    $request->validate([
      'cartId' => 'required|string',
      'variantId' => 'required|string',
      'quantity' => 'required|integer|min:1',
    ]);

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
        }
      }
      GRAPHQL;

    $variables = [
      'cartId' => $request->cartId,
      'lines' => [
        [
          'merchandiseId' => $request->variantId,
          'quantity' => $request->quantity,
        ]
      ]
    ];

    $data = $this->shopify->storefrontApiRequest($query, $variables);
    return response()->json($data['data']['cartLinesAdd']);
  }

  public function updateToCart(Request $request)
  {
    $request->validate([
      'cartId' => 'required|string',
      'lineId' => 'required|string',
      'quantity' => 'required|integer|min:1',
    ]);

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
        }
      }
    GRAPHQL;

    $variables = [
      'cartId' => $request->cartId,
      'lines' => [
        [
          'id' => $request->lineId,
          'quantity' => $request->quantity,
        ]
      ]
    ];

    $data = $this->shopify->storefrontApiRequest($query, $variables);
    return response()->json($data['data']['cartLinesUpdate']);
  }

  public function removeToCart(Request $request)
  {
    $request->validate([
      'cartId' => 'required|string',
      'lineId' => 'required|string',
    ]);

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
        }
      }
    GRAPHQL;

    $variables = [
      'cartId' => $request->cartId,
      'lineIds' => [$request->lineId],
    ];

    $data = $this->shopify->storefrontApiRequest($query, $variables);
    return response()->json($data['data']['cartLinesRemove']);
  }

  public function getCartDetails(Request $request)
  {
    $request->validate([
      'cartId' => 'required|string',
    ]);

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
    return response()->json($data['data']['cart']);
  }
}
