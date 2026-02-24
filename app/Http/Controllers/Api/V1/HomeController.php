<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\Home\SubscribeNewsletterRequest;
use App\Http\Resources\Home\HomeResource;
use App\Services\Shopify\HomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Home Controller (v1)
 * 
 * Handles home page and newsletter subscription endpoints.
 * Provides featured products, collections, and promotional content.
 * Extends BaseApiController for standardized responses.
 * 
 * Requirements: 9.1, 9.6, 9.7, 9.8, 9.9, 9.10
 */
class HomeController extends BaseApiController
{
    public function __construct(
        protected HomeService $homeService
    ) {}

    /**
     * Get home page data
     * 
     * Returns featured products, collections, and promotional content
     * for the mobile app home screen.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $homeData = $this->homeService->getHomePageData();

            return $this->success(
                'Home page data retrieved successfully',
                new HomeResource($homeData)
            );
        } catch (\Exception $e) {
            Log::error('Failed to fetch home page data', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch home page data',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Subscribe to newsletter
     * 
     * Subscribes the authenticated customer to the newsletter
     * by storing subscription status in customer metafields.
     * 
     * @param SubscribeNewsletterRequest $request
     * @return JsonResponse
     */
    public function subscribe(SubscribeNewsletterRequest $request): JsonResponse
    {
        try {
            $accessToken = $request->input('access_token') ?? $request->bearerToken();

            if (empty($accessToken)) {
                return $this->unauthorized('Access token is required');
            }

            $this->homeService->subscribeToNewsletter(
                $request->validated('email'),
                $accessToken
            );

            return $this->success(
                'Successfully subscribed to newsletter'
            );
        } catch (\App\Exceptions\ShopifyAuthException $e) {
            Log::warning('Newsletter subscription failed - authentication error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
            ]);

            return $this->unauthorized($e->getMessage());
        } catch (\App\Exceptions\ShopifyApiException $e) {
            Log::error('Newsletter subscription failed - API error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
            ]);

            return $this->error(
                'Failed to subscribe to newsletter',
                ['error' => $e->getMessage()],
                [],
                422
            );
        } catch (\Exception $e) {
            Log::error('Newsletter subscription failed', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to subscribe to newsletter',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }
}
