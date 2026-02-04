<?php

declare(strict_types=1);

namespace App\Http\Controllers\Apis\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProductResource;
use App\Contracts\Shopify\StorefrontServiceInterface;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;
    
    public function __construct(
        private readonly StorefrontServiceInterface $storefrontService
    ) {}
    
    /**
     * Get products list
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:50',
            'cursor' => 'nullable|string',
            'collection' => 'nullable|string',
            'search' => 'nullable|string',
        ]);
        
        $products = $this->storefrontService->getProducts(
            limit: $validated['limit'] ?? 20,
            cursor: $validated['cursor'] ?? null,
            collectionHandle: $validated['collection'] ?? null,
            query: $validated['search'] ?? null,
            countryCode: $request->input('detected_country') ?? 'US'
        );
        
        return $this->success(
            'Products fetched successfully',
            ProductResource::collection($products)
        );
    }
    
    /**
     * Get product by handle
     */
    public function show(Request $request, string $handle)
    {
        $product = $this->storefrontService->getProductByHandle(
            $handle,
            $request->input('detected_country') ?? 'US'
        );
        
        if (!$product) {
            return $this->error('Product not found', null, 404);
        }
        
        return $this->success(
            'Product retrieved successfully',
            new ProductResource($product)
        );
    }
}
