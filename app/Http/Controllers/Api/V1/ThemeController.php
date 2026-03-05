<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ThemeServiceInterface;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Resources\Theme\ThemeTemplateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Theme Controller (v1)
 * 
 * Handles Shopify theme template endpoints for mobile app rendering.
 * Provides public access to theme templates for dynamic content display.
 * Extends BaseApiController for standardized responses.
 */
class ThemeController extends BaseApiController
{
    public function __construct(
        protected ThemeServiceInterface $themeService
    ) {}

    /**
     * List theme templates
     * 
     * Returns a paginated list of theme templates.
     * Public endpoint - no authentication required.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $limit = $request->integer('limit', 10);
            $cursor = $request->string('cursor')->toString();

            $templates = $this->themeService->getTemplates($limit, $cursor ?: null);

            return $this->successWithPagination(
                'Theme templates retrieved successfully',
                ThemeTemplateResource::collection($templates['items']),
                $templates['pagination']
            );
        } catch (\Exception $e) {
            Log::error('Failed to fetch theme templates', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch theme templates',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Get theme template by handle
     * 
     * Returns a specific theme template by its handle.
     * Public endpoint - no authentication required.
     * 
     * @param string $handle
     * @return JsonResponse
     */
    public function show(string $handle): JsonResponse
    {
        try {
            $template = $this->themeService->getTemplateByHandle($handle);

            return $this->success(
                'Theme template retrieved successfully',
                new ThemeTemplateResource($template)
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            Log::info('Theme template not found', [
                'correlation_id' => $this->getCorrelationId(),
                'handle' => $handle,
            ]);

            return $this->notFound('Theme template not found');
        } catch (\Exception $e) {
            Log::error('Failed to fetch theme template', [
                'correlation_id' => $this->getCorrelationId(),
                'handle' => $handle,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch theme template',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Get theme template by type
     * 
     * Returns a theme template by type and optional resource handle.
     * Useful for determining which template to use for a specific resource.
     * Public endpoint - no authentication required.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getByType(Request $request): JsonResponse
    {
        try {
            $type = $request->input('type');
            $resourceHandle = $request->input('resource_handle');

            if (empty($type)) {
                return $this->validationError(
                    'Validation failed',
                    ['type' => ['The type field is required']]
                );
            }

            $template = $this->themeService->getTemplateByType($type, $resourceHandle);

            return $this->success(
                'Theme template retrieved successfully',
                new ThemeTemplateResource($template)
            );
        } catch (\InvalidArgumentException $e) {
            Log::info('Invalid template type', [
                'correlation_id' => $this->getCorrelationId(),
                'type' => $request->input('type'),
            ]);

            return $this->error(
                'Invalid template type',
                ['error' => $e->getMessage()],
                [],
                400
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            Log::info('Theme template not found', [
                'correlation_id' => $this->getCorrelationId(),
                'type' => $request->input('type'),
                'resource_handle' => $request->input('resource_handle'),
            ]);

            return $this->notFound('Theme template not found');
        } catch (\Exception $e) {
            Log::error('Failed to fetch theme template by type', [
                'correlation_id' => $this->getCorrelationId(),
                'type' => $request->input('type'),
                'resource_handle' => $request->input('resource_handle'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch theme template',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }
}
