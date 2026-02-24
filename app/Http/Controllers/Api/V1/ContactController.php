<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\Contact\ContactFormRequest;
use App\Services\Shopify\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Contact Controller (v1)
 * 
 * Handles contact form submission endpoint.
 * Provides public contact form for customer inquiries.
 * Extends BaseApiController for standardized responses.
 * 
 * Requirements: 9.5, 9.6, 9.7, 9.8, 9.9, 9.10
 */
class ContactController extends BaseApiController
{
    public function __construct(
        protected ContactService $contactService
    ) {}

    /**
     * Submit contact form
     * 
     * Processes contact form submissions and sends email notifications.
     * Public endpoint - no authentication required.
     * Rate limited to prevent abuse.
     * 
     * @param ContactFormRequest $request
     * @return JsonResponse
     */
    public function store(ContactFormRequest $request): JsonResponse
    {
        try {
            $this->contactService->submitContactForm($request->validated());

            Log::info('Contact form submitted successfully', [
                'correlation_id' => $this->getCorrelationId(),
                'email' => $request->validated('email'),
                'subject' => $request->validated('subject', 'General Inquiry'),
            ]);

            return $this->success(
                'Contact form submitted successfully',
                [],
                [],
                201
            );
        } catch (\App\Exceptions\ShopifyApiException $e) {
            Log::error('Contact form submission failed - API error', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'email' => $request->validated('email'),
            ]);

            return $this->error(
                'Failed to submit contact form',
                ['error' => $e->getMessage()],
                [],
                422
            );
        } catch (\Exception $e) {
            Log::error('Contact form submission failed', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'email' => $request->validated('email'),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to submit contact form',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }
}
