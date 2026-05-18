<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Contracts\Services\Sales\UpsellServiceInterface;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\Sales\GetUpsellSuggestionsRequest;
use App\Http\Resources\Sales\UpsellSuggestionResource;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Upsell suggestions endpoint.
 *
 *   POST /api/v1/ai/upsell/suggestions
 *
 * Always returns 200 with a populated structure. If Shopify is degraded the
 * upsells array is empty but the free-shipping gap is still returned (it
 * only needs the cart total + threshold).
 */
class UpsellController extends BaseApiController
{
    public function __construct(
        private readonly UpsellServiceInterface $upsell,
    ) {}

    public function suggestions(GetUpsellSuggestionsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $shopDomain = (string) $data['shop_domain'];
        $cartItems = (array) ($data['cart_items'] ?? []);
        $cartTotal = (float) ($data['cart_total'] ?? 0.0);
        $currency = $data['currency'] ?? null;

        try {
            $upsells = $this->upsell->getUpsells($cartItems, $shopDomain, $currency);
        } catch (Throwable $e) {
            report($e);
            $upsells = [];
        }

        $gap = null;
        try {
            $gap = $this->upsell->getFreeShippingGap($cartTotal, $shopDomain);
        } catch (Throwable $e) {
            report($e);
        }

        $threshold = (float) config('sales.upsell.default_free_shipping_threshold', 0);

        return $this->success('Upsell suggestions resolved.', [
            'upsells' => UpsellSuggestionResource::collection($upsells),
            'free_shipping_gap' => $gap,
            'threshold' => $threshold > 0 ? $threshold : null,
            'cart_total' => $cartTotal,
        ]);
    }
}
