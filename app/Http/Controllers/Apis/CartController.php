<?php

namespace App\Http\Controllers\Apis;

use App\Contracts\Shopify\CartServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CartResource;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CartController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CartServiceInterface $cartService
    ) {}

    public function createCart()
    {
        // Legacy creates empty cart
        $cart = $this->cartService->createGuestCart([]);

        return $this->success(
            'Cart created successfully',
            new CartResource($cart),
            200 // Legacy uses 200 for creation
        );
    }

    public function addToCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cartId' => 'required|string',
            'variantId' => 'required|string',
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $items = [
            [
                'merchandiseId' => $request->variantId,
                'quantity' => (int) $request->quantity,
            ]
        ];

        $cart = $this->cartService->addCartLines($request->cartId, $items);

        return $this->success(
            'Product has been added in cart successfully',
            new CartResource($cart)
        );
    }

    public function updateToCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cartId' => 'required|string',
            'lineId' => 'required|string',
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $items = [
            [
                'id' => $request->lineId,
                'quantity' => (int) $request->quantity,
            ]
        ];

        $cart = $this->cartService->updateCartLines($request->cartId, $items);

        return $this->success(
            'Product has been updated in cart successfully',
            new CartResource($cart)
        );
    }

    // Preserving the grammatically incorrect name for backward compatibility
    public function removeToCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cartId' => 'required|string',
            'lineId' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $cart = $this->cartService->removeCartLines($request->cartId, [$request->lineId]);

        return $this->success(
            'Product has been from cart successfully', // Preserving legacy message typo? "Product has been from cart"
            new CartResource($cart)
        );
    }

    public function getCartDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cartId' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $cart = $this->cartService->getCart($request->cartId);

        if (!$cart) {
            return $this->error('Cart not found', null, 404);
        }

        return $this->success(
            'Cart details fetched successfully',
            new CartResource($cart)
        );
    }

    public function cartBuyerIdentityUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cartId' => 'required|string',
            // 'userId' => 'required|string', // Legacy commented this out
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }
        
        $customerData = $request->input('shopify_customer_data', []);
        $email = $customerData['email'] ?? null;
        $token = $request->bearerToken();

        $cart = $this->cartService->updateBuyerIdentity(
            cartId: $request->cartId,
            email: $email,
            customerAccessToken: $token
        );

        return $this->success(
            'User has been attached with cart successfully',
            new CartResource($cart)
        );
    }
}
