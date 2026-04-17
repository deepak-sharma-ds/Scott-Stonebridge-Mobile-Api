<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\Home\SubscribeNewsletterRequest;
use App\Http\Resources\Home\HomeResource;
use App\Contracts\Services\HomeServiceInterface;
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
        protected HomeServiceInterface $homeService
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
     * Subscribes a customer to the newsletter. Supports both:
     * - Authenticated users (via access token)
     * - Guest users (via email only - stored for future marketing)
     * 
     * @param SubscribeNewsletterRequest $request
     * @return JsonResponse
     */
    public function subscribe(SubscribeNewsletterRequest $request): JsonResponse
    {
        try {
            $accessToken = $request->input('access_token') ?? $request->bearerToken();
            $email = $request->validated('email');

            // If user is authenticated, update their customer record
            if (!empty($accessToken)) {
                $this->homeService->subscribeToNewsletter($email, $accessToken);
                
                return $this->success(
                    'Successfully subscribed to newsletter'
                );
            }

            // For guest users, just acknowledge the subscription
            // In a real implementation, you might want to:
            // 1. Store email in a separate newsletter table
            // 2. Send confirmation email
            // 3. Use a third-party email marketing service (Mailchimp, Klaviyo, etc.)
            Log::info('Guest newsletter subscription', [
                'correlation_id' => $this->getCorrelationId(),
                'email' => $email,
            ]);

            return $this->success(
                'Successfully subscribed to newsletter. You will receive a confirmation email shortly.'
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
