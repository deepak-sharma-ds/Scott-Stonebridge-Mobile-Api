<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProductServiceInterface;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Resources\Product\ProductResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product Controller (v1)
 * 
 * Handles product-related API endpoints.
 * Extends BaseApiController for standardized responses.
 * 
 * Requirements: 2.1, 2.2, 5.4, 11.6
 */
class ProductController extends BaseApiController
{
    public function __construct(
        protected ProductServiceInterface $productService
    ) {}

    /**
     * Get product listing with pagination
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $limit = (int) $request->input('limit', 20);
            $cursor = $request->input('cursor');
            $filters = [
                'sortKey' => $request->input('sort_key', 'TITLE'),
                'reverse' => $request->boolean('reverse', false),
                'query' => $request->input('query'),
                'tag' => $request->input('tag', null),
            ];

            $products = $this->productService->getAllProducts($limit, $cursor, $filters);

            return $this->success(
                'Products fetched successfully',
                [
                    'products' => ProductResource::collection($products),
                ]
            );
        } catch (\Exception $e) {
            return $this->error(
                'Failed to fetch products',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Get product detail by handle
     * 
     * @param string $handle
     * @return JsonResponse
     */
    public function show(string $handle): JsonResponse
    {
        try {
            $product = $this->productService->getProductByHandle($handle);

            return $this->success(
                'Product fetched successfully',
                [
                    'product' => new ProductResource($product),
                ]
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Exception $e) {
            return $this->error(
                'Failed to fetch product',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Search products
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $query = $request->input('query', '');
            $limit = (int) $request->input('limit', 20);
            $cursor = $request->input('cursor');

            if (empty($query)) {
                return $this->validationError(
                    'Search query is required',
                    ['query' => ['The query field is required']]
                );
            }

            $products = $this->productService->searchProducts($query, $limit, $cursor);

            return $this->success(
                'Products searched successfully',
                [
                    'products' => ProductResource::collection($products),
                    'query' => $query,
                ]
            );
        } catch (\Exception $e) {
            return $this->error(
                'Failed to search products',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }
}
