<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\AppVersionServiceInterface;
use App\DTOs\AppVersion\AppVersionCheckDTO;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\App\AppVersionCheckRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * App Version Controller (v1)
 *
 * Handles mobile app version verification and compulsory update checks.
 * Public endpoint - accessible before authentication/login on app startup.
 */
class AppVersionController extends BaseApiController
{
    public function __construct(
        protected AppVersionServiceInterface $appVersionService
    ) {}

    /**
     * Check app version and update requirements.
     *
     * GET /api/v1/app/version
     * GET /api/app/version
     */
    public function show(AppVersionCheckRequest $request): JsonResponse
    {
        try {
            $dto = AppVersionCheckDTO::fromRequest($request->validated());
            $result = $this->appVersionService->checkVersion($dto);

            return $this->success(
                'App version check completed successfully',
                $result->toArray()
            );
        } catch (\Throwable $e) {
            Log::error('App version check failed', [
                'correlation_id' => $this->getCorrelationId(),
                'platform' => $request->input('platform'),
                'version' => $request->input('version') ?? $request->input('app_version'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to check app version',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }
}
