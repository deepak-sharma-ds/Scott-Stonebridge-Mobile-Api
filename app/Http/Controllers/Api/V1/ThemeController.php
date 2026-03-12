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
 * 
 * Uses Shopify Admin API to fetch theme template assets from Online Store 2.0.
 * Extends BaseApiController for standardized responses.
 */
class ThemeController extends BaseApiController
{
    public function __construct(
        protected ThemeServiceInterface $themeService
    ) {}

    /**
     * Get active theme information
     * 
     * Returns the active theme ID and name.
     * Public endpoint - no authentication required.
     * 
     * @return JsonResponse
     */
    public function getActiveTheme(): JsonResponse
    {
        try {
            $theme = $this->themeService->getActiveTheme();

            return $this->success(
                'Active theme retrieved successfully',
                $theme
            );
        } catch (\Exception $e) {
            Log::error('Failed to fetch active theme', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch active theme',
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
     * Optionally includes rendered HTML if requested.
     * Public endpoint - no authentication required.
     * 
     * @param Request $request
     * @param string $handle Template handle (e.g., "page", "page.about", "product.custom")
     * @return JsonResponse
     */
    public function show(Request $request, string $handle): JsonResponse
    {
        try {
            $includeHtml = $request->boolean('include_html', false);
            $pageHandle = $request->input('page_handle');

            // Validate page_handle if HTML is requested
            if ($includeHtml && empty($pageHandle)) {
                return $this->validationError(
                    'Validation failed',
                    ['page_handle' => ['The page_handle field is required when include_html is true']]
                );
            }

            $template = $this->themeService->getTemplateByHandle($handle, $includeHtml, $pageHandle);

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
     * Get template JSON by name
     * 
     * Returns the template JSON configuration from theme assets.
     * Public endpoint - no authentication required.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getTemplateJson(Request $request): JsonResponse
    {
        try {
            $templateName = $request->input('template_name');
            $themeId = $request->input('theme_id');

            if (empty($templateName)) {
                return $this->validationError(
                    'Validation failed',
                    ['template_name' => ['The template_name field is required']]
                );
            }

            $templateJson = $this->themeService->getTemplateJson(
                $templateName, 
                $themeId ? (int) $themeId : null
            );

            return $this->success(
                'Template JSON retrieved successfully',
                $templateJson
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            Log::info('Template JSON not found', [
                'correlation_id' => $this->getCorrelationId(),
                'template_name' => $request->input('template_name'),
            ]);

            return $this->notFound('Template JSON not found');
        } catch (\Exception $e) {
            Log::error('Failed to fetch template JSON', [
                'correlation_id' => $this->getCorrelationId(),
                'template_name' => $request->input('template_name'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch template JSON',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Get rendered HTML for a page
     * 
     * Returns the fully rendered HTML of a page from the storefront.
     * Public endpoint - no authentication required.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getRenderedHtml(Request $request): JsonResponse
    {
        try {
            $handle = $request->input('handle');

            if (empty($handle)) {
                return $this->validationError(
                    'Validation failed',
                    ['handle' => ['The handle field is required']]
                );
            }

            $html = $this->themeService->fetchRenderedHtml($handle);

            return $this->success(
                'Rendered HTML retrieved successfully',
                ['html' => $html]
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            Log::info('Page not found', [
                'correlation_id' => $this->getCorrelationId(),
                'handle' => $request->input('handle'),
            ]);

            return $this->notFound('Page not found');
        } catch (\Exception $e) {
            Log::error('Failed to fetch rendered HTML', [
                'correlation_id' => $this->getCorrelationId(),
                'handle' => $request->input('handle'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch rendered HTML',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Get theme template by type
     * 
     * Returns a theme template by type and optional suffix.
     * Optionally includes rendered HTML if requested.
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
            $suffix = $request->input('suffix');
            $includeHtml = $request->boolean('include_html', false);
            $pageHandle = $request->input('page_handle');

            if (empty($type)) {
                return $this->validationError(
                    'Validation failed',
                    ['type' => ['The type field is required']]
                );
            }

            // Validate page_handle if HTML is requested
            if ($includeHtml && empty($pageHandle)) {
                return $this->validationError(
                    'Validation failed',
                    ['page_handle' => ['The page_handle field is required when include_html is true']]
                );
            }

            $template = $this->themeService->getTemplateByType($type, $suffix, $includeHtml, $pageHandle);

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
                'suffix' => $request->input('suffix'),
            ]);

            return $this->notFound('Theme template not found');
        } catch (\Exception $e) {
            Log::error('Failed to fetch theme template by type', [
                'correlation_id' => $this->getCorrelationId(),
                'type' => $request->input('type'),
                'suffix' => $request->input('suffix'),
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
