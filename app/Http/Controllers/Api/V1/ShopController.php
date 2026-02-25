<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ShopServiceInterface;
use App\Http\Controllers\Base\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Shop Controller (v1)
 * 
 * Handles shop-level endpoints including markets and currency information.
 * Public endpoints - no authentication required.
 * 
 * Requirements: Currency API
 */
class ShopController extends BaseApiController
{
    public function __construct(
        protected ShopServiceInterface $shopService
    ) {}

    /**
     * Get supported currencies
     * 
     * Returns a list of all supported currency codes for the shop.
     * Public endpoint - no authentication required.
     * 
     * @return JsonResponse
     */
    public function currencies(): JsonResponse
    {
        try {
            $currencies = $this->shopService->getSupportedCurrencies();

            return $this->success(
                'Supported currencies retrieved successfully',
                [
                    'currencies' => $currencies,
                    'count' => count($currencies),
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to fetch supported currencies', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch supported currencies',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Get shop markets
     * 
     * Returns detailed information about all supported markets including
     * countries, currencies, and regional settings.
     * Public endpoint - no authentication required.
     * 
     * @return JsonResponse
     */
    public function markets(): JsonResponse
    {
        try {
            $shop = $this->shopService->getMarkets();

            return $this->success(
                'Shop markets retrieved successfully',
                $shop->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to fetch shop markets', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch shop markets',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }
}
