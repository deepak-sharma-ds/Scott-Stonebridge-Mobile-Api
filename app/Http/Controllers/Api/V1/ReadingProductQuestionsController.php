<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailReadingProduct;
use Illuminate\Http\JsonResponse;

class ReadingProductQuestionsController extends Controller
{
    /**
     * Public, unauthenticated (see ADR 0019). Returns question schema for a
     * Checkout-Registered reading product — a row that exists and is
     * active. An active product with an empty `questions_schema` is a
     * legitimate, permanent state (some readings need no personalization):
     * it still returns 200 with an empty `questions` array, not a 404. Only
     * a missing row or a paused product (is_active=false) are treated as
     * unregistered — both return the same generic 404
     * {registered:false}, indistinguishable to the caller. Never returns
     * prompt_template or any email-only field (see ADR 0019).
     */
    public function show(string $shopifyProductId): JsonResponse
    {
        $product = EmailReadingProduct::where('shopify_product_id', $shopifyProductId)->first();

        if (! $product || ! $product->isCheckoutRegistered()) {
            return response()->json(['registered' => false], 404);
        }

        return response()->json([
            'registered' => true,
            'product' => [
                'shopify_product_id' => $product->shopify_product_id,
                'name' => $product->name,
                'slug' => $product->slug,
                'header_image' => $product->header_image,
                'questions' => collect($product->questions_schema)->map(fn (array $q) => [
                    'key' => $q['key'] ?? '',
                    'label' => $q['label'] ?? '',
                    'type' => $q['type'] ?? 'text',
                    'required' => (bool) ($q['required'] ?? false),
                ])->values(),
            ],
        ], 200);
    }
}
