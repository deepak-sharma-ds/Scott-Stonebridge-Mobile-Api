<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ContentServiceInterface;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Resources\Content\ArticleResource;
use App\Http\Resources\Content\BlogResource;
use App\Http\Resources\Content\PageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Content Controller (v1)
 * 
 * Handles CMS content endpoints including pages, blogs, articles, and policies.
 * Provides public access to store content for mobile app display.
 * Extends BaseApiController for standardized responses.
 * 
 * Requirements: 9.4, 9.6, 9.7, 9.8, 9.9, 9.10
 */
class ContentController extends BaseApiController
{
    public function __construct(
        protected ContentServiceInterface $contentService
    ) {}

    /**
     * Get page by handle
     * 
     * Returns a Shopify page by its handle (URL slug).
     * Public endpoint - no authentication required.
     * 
     * @param string $handle
     * @return JsonResponse
     */
    public function showPage(string $handle): JsonResponse
    {
        try {
            $page = $this->contentService->getPageByHandle($handle);

            return $this->success(
                'Page retrieved successfully',
                new PageResource($page)
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            Log::info('Page not found', [
                'correlation_id' => $this->getCorrelationId(),
                'handle' => $handle,
            ]);

            return $this->notFound('Page not found');
        } catch (\Exception $e) {
            Log::error('Failed to fetch page', [
                'correlation_id' => $this->getCorrelationId(),
                'handle' => $handle,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch page',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Get policy by type
     * 
     * Returns a Shopify policy page by type (privacy, refund, shipping, terms).
     * Public endpoint - no authentication required.
     * 
     * @param string $type
     * @return JsonResponse
     */
    public function showPolicy(string $type): JsonResponse
    {
        try {
            $policy = $this->contentService->getPolicyByType($type);

            return $this->success(
                'Policy retrieved successfully',
                new PageResource($policy)
            );
        } catch (\InvalidArgumentException $e) {
            Log::info('Invalid policy type', [
                'correlation_id' => $this->getCorrelationId(),
                'type' => $type,
            ]);

            return $this->error(
                'Invalid policy type',
                ['error' => $e->getMessage()],
                [],
                400
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            Log::info('Policy not found', [
                'correlation_id' => $this->getCorrelationId(),
                'type' => $type,
            ]);

            return $this->notFound('Policy not found');
        } catch (\Exception $e) {
            Log::error('Failed to fetch policy', [
                'correlation_id' => $this->getCorrelationId(),
                'type' => $type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch policy',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * List blogs
     * 
     * Returns a paginated list of blogs.
     * Public endpoint - no authentication required.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function indexBlogs(Request $request): JsonResponse
    {
        try {
            $limit = $request->integer('limit', 10);
            $cursor = $request->string('cursor')->toString();

            $blogs = $this->contentService->getBlogs($limit, $cursor ?: null);

            return $this->successWithPagination(
                'Blogs retrieved successfully',
                BlogResource::collection($blogs['items']),
                $blogs['pagination']
            );
        } catch (\Exception $e) {
            Log::error('Failed to fetch blogs', [
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch blogs',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * List articles for a blog
     * 
     * Returns a paginated list of articles for a specific blog.
     * Public endpoint - no authentication required.
     * 
     * @param Request $request
     * @param string $blogHandle
     * @return JsonResponse
     */
    public function indexArticles(Request $request, string $blogHandle): JsonResponse
    {
        try {
            $limit = $request->integer('limit', 10);
            $cursor = $request->string('cursor')->toString();

            $articles = $this->contentService->getArticles(
                $blogHandle,
                $limit,
                $cursor ?: null
            );

            return $this->successWithPagination(
                'Articles retrieved successfully',
                ArticleResource::collection($articles['items']),
                $articles['pagination']
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            Log::info('Blog not found', [
                'correlation_id' => $this->getCorrelationId(),
                'blog_handle' => $blogHandle,
            ]);

            return $this->notFound('Blog not found');
        } catch (\Exception $e) {
            Log::error('Failed to fetch articles', [
                'correlation_id' => $this->getCorrelationId(),
                'blog_handle' => $blogHandle,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch articles',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Get single article
     * 
     * Returns a single article by blog handle and article handle.
     * Public endpoint - no authentication required.
     * 
     * @param string $blogHandle
     * @param string $articleHandle
     * @return JsonResponse
     */
    public function showArticle(string $blogHandle, string $articleHandle): JsonResponse
    {
        try {
            $article = $this->contentService->getArticle($blogHandle, $articleHandle);

            return $this->success(
                'Article retrieved successfully',
                new ArticleResource($article)
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            Log::info('Article not found', [
                'correlation_id' => $this->getCorrelationId(),
                'blog_handle' => $blogHandle,
                'article_handle' => $articleHandle,
            ]);

            return $this->notFound('Article not found');
        } catch (\Exception $e) {
            Log::error('Failed to fetch article', [
                'correlation_id' => $this->getCorrelationId(),
                'blog_handle' => $blogHandle,
                'article_handle' => $articleHandle,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to fetch article',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Resolve URL
     * 
     * Resolves a Shopify URL to determine its resource type and handle.
     * Useful for deep linking and navigation in the mobile app.
     * Public endpoint - no authentication required.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function resolve(Request $request): JsonResponse
    {
        try {
            $url = $request->input('url');

            if (empty($url)) {
                return $this->validationError(
                    'Validation failed',
                    ['url' => ['The url field is required']]
                );
            }

            $resolved = $this->contentService->resolveUrl($url);

            return $this->success(
                'URL resolved successfully',
                $resolved
            );
        } catch (\Exception $e) {
            Log::error('Failed to resolve URL', [
                'correlation_id' => $this->getCorrelationId(),
                'url' => $request->input('url'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to resolve URL',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }
}
