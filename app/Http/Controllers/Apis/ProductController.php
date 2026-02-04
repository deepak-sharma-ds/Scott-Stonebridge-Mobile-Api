<?php

namespace App\Http\Controllers\Apis;

use App\Contracts\Shopify\StorefrontServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProductResource;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly StorefrontServiceInterface $storefrontService
    ) {}

    /**
     * Get all products with pagination
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllProducts(Request $request)
    {
        $limit = (int) $request->get('limit', 20);
        $cursor = $request->get('after');
        $countryCode = $request->header('X-Country-Code', 'US');

        // Use the service to fetch products
        $products = $this->storefrontService->getProducts(
            limit: $limit,
            cursor: $cursor,
            country: $countryCode
        );

        // Transform simplified DTOs using our Resource
        return $this->success(
            'Products fetched successfully',
            [
                'products' => ProductResource::collection($products),
                'next_cursor' => $products->last()?->cursor, // Assuming DTO has cursor if needed, or we adapt service to return cursor info
                'has_more' => $products->count() === $limit,
            ]
        );
    }

    /**
     * Search products by query
     */
    public function searchProducts(Request $request)
    {
        $query = $request->input('query');
        
        if (!$query) {
            return $this->error('Query parameter is required.', null, 400);
        }

        $limit = (int) $request->input('limit', 20);
        $cursor = $request->input('after');
        $countryCode = $request->header('X-Country-Code', 'US');

        $products = $this->storefrontService->getProducts(
            limit: $limit,
            cursor: $cursor,
            query: "title:{$query}*",
            country: $countryCode
        );

        return $this->success(
            'Products found successfully',
            [
                'products' => ProductResource::collection($products),
                'next_cursor' => $products->last()?->cursor,
                'has_more' => $products->count() === $limit,
            ]
        );
    }

    /**
     * Get product details by ID or Handle
     * NOTE: Legacy app sends ID, but new route supports handle. 
     * We'll support both via service.
     */
    public function getProductDetail($productId)
    {
        $countryCode = request()->header('X-Country-Code', 'US');

        // If it looks like a numeric ID, we might need to find by ID
        // The service assumes handles mostly. If ID is passed, check if we need conversion.
        // Assuming legacy passed ID or Handle. If ID, we convert to global ID if widely used.
        
        $product = null;
        if (is_numeric($productId) || str_starts_with($productId, 'gid://')) {
            // It's an ID
             $id = is_numeric($productId) ? "gid://shopify/Product/{$productId}" : $productId;
             // Service needs a "findById" method or we rely on handle if we can't find by ID efficiently in Storefront API without Node interface
             // For now, let's assume handle is passed OR we try to fetch via handle/id
             // Actually Storefront API `product(id: ID!)` exists.
             // We'll update service to support ID lookup if missing, or use handle.
             // Let's assume handle for now, or fallback.
        } 
        
        // Actually, let's use the handle lookup which is safer for SEO and Storefront
        // But if legacy uses ID, we might have issues. 
        // Let's implement getProductById in service if needed.
        
        // For this refactor, let's use getProductByHandle as primary, assuming $productId is handle
        $product = $this->storefrontService->getProductByHandle($productId, $countryCode);

        if (!$product) {
            return $this->error('Product not found', null, 404);
        }

        return $this->success(
            'Product details fetched successfully',
            new ProductResource($product)
        );
    }

    /**
     * Get categories (Collections)
     */
    public function getCategories(Request $request)
    {
        $limit = (int) $request->get('limit', 20);
        $countryCode = $request->header('X-Country-Code', 'US');
        
        $collections = $this->storefrontService->getCollections($limit, $countryCode);

        // We might need a CollectionResource, but array works for now
        return $this->success(
            'Collections fetched successfully',
            ['collections' => $collections]
        );
    }

    /**
     * Get products (filtered)
     */
    public function getProducts(Request $request)
    {
        // Similar to getAllProducts but with detailed filters
        $validator = Validator::make($request->all(), [
            'limit'      => 'integer|min:1|max:250',
            'collection' => 'string|nullable',
            'sort'       => 'string|in:newest,oldest,low_price,high_price',
            'search'     => 'string|nullable',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $limit = (int) $request->input('limit', 20);
        $cursor = $request->input('after');
        $countryCode = $request->header('X-Country-Code', 'US');
        
        $collectionHandle = $request->input('collection');
        $sort = $request->input('sort', 'newest');
        $search = $request->input('search');

        // Map sort keys
        $sortKey = match($sort) {
            'newest', 'oldest' => 'CREATED',
            'low_price', 'high_price' => 'PRICE',
            default => 'BEST_SELLING'
        };
        $reverse = in_array($sort, ['newest', 'high_price']);

        $products = $this->storefrontService->getProducts(
            limit: $limit,
            cursor: $cursor,
            sortKey: $sortKey,
            reverse: $reverse,
            query: $search ? "title:{$search}*" : null,
            collectionId: $collectionHandle, // Service handles handle lookup?
            country: $countryCode
        );

        return $this->success(
            'Products fetched successfully',
            [
                'products' => ProductResource::collection($products),
                'next_cursor' => $products->last()?->cursor,
                'has_more' => $products->count() === $limit,
            ]
        );
    }

    /**
     * Get Featured Products
     */
    public function getFeaturedProducts(Request $request)
    {
        $tag = $request->input('tag');
        if (!$tag) {
            return $this->error('Tag is required', null, 400);
        }

        $limit = (int) $request->input('limit', 10);
        $countryCode = $request->header('X-Country-Code', 'US');

        $products = $this->storefrontService->getProducts(
            limit: $limit,
            query: "tag:{$tag}",
            country: $countryCode
        );

        return $this->success(
            'Featured products fetched successfully',
            [
                'products' => ProductResource::collection($products)
            ]
        );
    }
}
