<?php

declare(strict_types=1);

namespace App\Http\Controllers\Apis\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CartResource;
use App\Contracts\Shopify\CartServiceInterface;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class GuestCartController extends Controller
{
    use ApiResponse;
    
    public function __construct(
        private readonly CartServiceInterface $cartService
    ) {}
    
    /**
     * Create a new guest cart
     */
    public function create(Request $request)
    {
        $validated = $request->validate([
            'line_items' => 'nullable|array',
            'line_items.*.merchandiseId' => 'required|string',
            'line_items.*.quantity' => 'required|integer|min:1',
        ]);
        
        $cart = $this->cartService->createGuestCart(
            lineItems: $validated['line_items'] ?? [],
            countryCode: $request->input('detected_country') ?? 'US'
        );
        
        return $this->success(
            'Cart created successfully',
            new CartResource($cart),
            201
        );
    }
    
    /**
     * Get cart details
     */
    public function show(string $cartId)
    {
        $cart = $this->cartService->getCart($cartId);
        
        if (!$cart) {
            return $this->error('Cart not found', null, 404);
        }
        
        return $this->success(
            'Cart retrieved successfully',
            new CartResource($cart)
        );
    }
    
    /**
     * Add items to cart
     */
    public function addItems(string $cartId, Request $request)
    {
        $validated = $request->validate([
            'line_items' => 'required|array',
            'line_items.*.merchandiseId' => 'required|string',
            'line_items.*.quantity' => 'required|integer|min:1',
        ]);
        
        $cart = $this->cartService->addCartLines($cartId, $validated['line_items']);
        
        return $this->success(
            'Items added to cart',
            new CartResource($cart)
        );
    }
    
    /**
     * Update cart items
     */
    public function updateItems(string $cartId, Request $request)
    {
        $validated = $request->validate([
            'lines' => 'required|array',
            'lines.*.id' => 'required|string',
            'lines.*.quantity' => 'required|integer|min:0',
        ]);
        
        $cart = $this->cartService->updateCartLines($cartId, $validated['lines']);
        
        return $this->success(
            'Cart updated successfully',
            new CartResource($cart)
        );
    }
    
    /**
     * Remove items from cart
     */
    public function removeItems(string $cartId, Request $request)
    {
        $validated = $request->validate([
            'line_ids' => 'required|array',
            'line_ids.*' => 'required|string',
        ]);
        
        $cart = $this->cartService->removeCartLines($cartId, $validated['line_ids']);
        
        return $this->success(
            'Items removed from cart',
            new CartResource($cart)
        );
    }
    
    /**
     * Update buyer information
     */
    public function updateBuyerInfo(string $cartId, Request $request)
    {
        $validated = $request->validate([
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'country_code' => 'nullable|string|size:2',
        ]);
        
        $cart = $this->cartService->updateBuyerIdentity(
            $cartId,
            $validated['email'] ?? null,
            $validated['phone'] ?? null,
            $validated['country_code'] ?? null
        );
        
        return $this->success(
            'Buyer information updated',
            new CartResource($cart)
        );
    }
    
    /**
     * Get checkout URL
     */
    public function getCheckoutUrl(string $cartId)
    {
        $checkoutUrl = $this->cartService->getCheckoutUrl($cartId);
        
        if (!$checkoutUrl) {
            return $this->error('Cart not found', null, 404);
        }
        
        return $this->success('Checkout URL retrieved', [
            'checkout_url' => $checkoutUrl,
        ]);
    }
    
    /**
     * Apply discount code
     */
    public function applyDiscount(string $cartId, Request $request)
    {
        $validated = $request->validate([
            'discount_code' => 'required|string',
        ]);
        
        $cart = $this->cartService->applyDiscountCode($cartId, $validated['discount_code']);
        
        return $this->success(
            'Discount code applied',
            new CartResource($cart)
        );
    }
}
