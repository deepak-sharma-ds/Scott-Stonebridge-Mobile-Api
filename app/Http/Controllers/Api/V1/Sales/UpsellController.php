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
 * Always returns 200. Surfaces an upsell / cross-sell product grid only.
 * If Shopify is degraded the upsells array is simply empty.
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
        $currency = $data['currency'] ?? null;

        try {
            $upsells = $this->upsell->getUpsells($cartItems, $shopDomain, $currency);
        } catch (Throwable $e) {
            report($e);
            $upsells = [];
        }

        return $this->success('Upsell suggestions resolved.', [
            'upsells' => UpsellSuggestionResource::collection($upsells),
        ]);
    }
}
