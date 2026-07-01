<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\FreeReadingServiceInterface;
use App\DTOs\FreeReading\FreeReadingFormDTO;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\FreeReading\FreeReadingFormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Free Reading Controller (v1)
 *
 * Handles the "Free Email Reading" lead-capture form submission endpoint.
 * Public endpoint used by the mobile app. Extends BaseApiController for
 * standardized responses.
 */
class FreeReadingController extends BaseApiController
{
    public function __construct(
        protected FreeReadingServiceInterface $freeReadingService
    ) {}

    /**
     * Submit the free email reading form.
     *
     * Stores the submission and notifies the admin. Public, rate limited.
     */
    public function store(FreeReadingFormRequest $request): JsonResponse
    {
        try {
            $form = FreeReadingFormDTO::fromRequest($request->validated());
            $this->freeReadingService->submitFreeReadingForm($form);

            Log::info('Free reading form submitted successfully', [
                'correlation_id' => $this->getCorrelationId(),
                'email' => $request->validated('email'),
            ]);

            return $this->success(
                'Free reading form submitted successfully',
                [],
                [],
                201
            );
        } catch (\Exception $e) {
            Log::error('Free reading form submission failed', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'email' => $request->validated('email'),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to submit free reading form',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }
}
