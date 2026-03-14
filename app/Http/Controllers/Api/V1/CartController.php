<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\CartServiceInterface;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\Cart\AddToCartRequest;
use App\Http\Requests\Cart\CreateCartRequest;
use App\Http\Requests\Cart\UpdateBuyerIdentityRequest;
use App\Http\Requests\Cart\UpdateCartRequest;
use App\Http\Resources\Cart\CartResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cart Controller (v1)
 * 
 * Handles cart-related API endpoints.
 * Supports both guest and authenticated cart operations.
 * Extends BaseApiController for standardized responses.
 * 
 * Requirements: 2.1, 2.2, 5.4, 11.6
 */
class CartController extends BaseApiController
{
    public function __construct(
        protected CartServiceInterface $cartService
    ) {}

    /**
     * Create a new cart
     * 
     * @param CreateCartRequest $request
     * @return JsonResponse
     */
    public function store(CreateCartRequest $request): JsonResponse
    {
        try {
            $accessToken = $request->input('access_token');

            $cart = $this->cartService->createCart($accessToken);

            return $this->success(
                'Cart created successfully',
                [
                    'cart' => new CartResource($cart),
                ],
                [],
                201
            );
        } catch (\Exception $e) {
            return $this->error(
                'Failed to create cart',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Get cart by ID
     * 
     * @param string $cartId
     * @return JsonResponse
     */
    public function show(string $cartId): JsonResponse
    {
        try {
            $cart = $this->cartService->getCart($cartId);

            return $this->success(
                'Cart fetched successfully',
                [
                    'cart' => new CartResource($cart),
                ]
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Exception $e) {
            return $this->error(
                'Failed to fetch cart',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Add item to cart
     * 
     * @param AddToCartRequest $request
     * @return JsonResponse
     */
    public function addItem(AddToCartRequest $request): JsonResponse
    {
        try {
            $cartId = $request->input('cart_id');
            $lines = $request->input('lines');

            $cart = $this->cartService->addLineItems($cartId, $lines);

            return $this->success(
                'Item added to cart successfully',
                [
                    'cart' => new CartResource($cart),
                ]
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\App\Exceptions\ShopifyApiException $e) {
            return $this->error(
                'Failed to add item to cart',
                ['error' => $e->getMessage()],
                [],
                422
            );
        } catch (\Exception $e) {
            return $this->error(
                'Failed to add item to cart',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Update cart item quantity
     * 
     * @param UpdateCartRequest $request
     * @return JsonResponse
     */
    public function updateItem(UpdateCartRequest $request): JsonResponse
    {
        try {
            $cartId = $request->input('cart_id');
            $lineId = $request->input('line_id');
            $quantity = (int) $request->input('quantity', 1);

            if (empty($cartId)) {
                return $this->validationError(
                    'Validation failed',
                    ['cart_id' => ['The cart_id field is required']]
                );
            }

            if (empty($lineId)) {
                return $this->validationError(
                    'Validation failed',
                    ['line_id' => ['The line_id field is required']]
                );
            }

            if ($quantity < 0) {
                return $this->validationError(
                    'Validation failed',
                    ['quantity' => ['The quantity must be at least 0']]
                );
            }

            $cart = $this->cartService->updateLineItem($cartId, $lineId, $quantity);

            return $this->success(
                'Cart item updated successfully',
                [
                    'cart' => new CartResource($cart),
                ]
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\App\Exceptions\ShopifyApiException $e) {
            return $this->error(
                'Failed to update cart item',
                ['error' => $e->getMessage()],
                [],
                422
            );
        } catch (\Exception $e) {
            return $this->error(
                'Failed to update cart item',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Remove item from cart
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function removeItem(Request $request): JsonResponse
    {
        try {
            $cartId = $request->input('cart_id');
            $lineId = $request->input('line_id');

            if (empty($cartId)) {
                return $this->validationError(
                    'Validation failed',
                    ['cart_id' => ['The cart_id field is required']]
                );
            }

            if (empty($lineId)) {
                return $this->validationError(
                    'Validation failed',
                    ['line_id' => ['The line_id field is required']]
                );
            }

            $cart = $this->cartService->removeLineItem($cartId, $lineId);

            return $this->success(
                'Item removed from cart successfully',
                [
                    'cart' => new CartResource($cart),
                ]
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\App\Exceptions\ShopifyApiException $e) {
            return $this->error(
                'Failed to remove cart item',
                ['error' => $e->getMessage()],
                [],
                422
            );
        } catch (\Exception $e) {
            return $this->error(
                'Failed to remove cart item',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Update cart buyer identity
     * 
     * @param string $cartId
     * @param UpdateBuyerIdentityRequest $request
     * @return JsonResponse
     */
    public function updateBuyerIdentity(string $cartId, UpdateBuyerIdentityRequest $request): JsonResponse
    {
        try {
            $email = $request->validated('email');

            $cart = $this->cartService->updateBuyerIdentity($cartId, $email);

            return $this->success(
                'Buyer identity updated successfully',
                [
                    'cart' => new CartResource($cart),
                ]
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\App\Exceptions\ShopifyApiException $e) {
            return $this->error(
                'Failed to update buyer identity',
                ['error' => $e->getMessage()],
                [],
                422
            );
        } catch (\Exception $e) {
            return $this->error(
                'Failed to update buyer identity',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }
}
