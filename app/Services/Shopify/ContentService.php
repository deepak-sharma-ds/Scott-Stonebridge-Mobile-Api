<?php

namespace App\Services\Shopify;

use App\Contracts\Services\ContentServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Content\PageDTO;
use App\DTOs\Content\BlogDTO;
use App\DTOs\Content\ArticleDTO;
use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyNotFoundException;
use App\Services\Base\BaseService;
use App\Services\Cache\ShopifyCacheStrategy;
use App\Traits\CacheWithFallback;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Content Service
 * 
 * Handles CMS content operations including pages, blogs, articles, and policies
 * using the Shopify Storefront API. Implements intelligent caching with different
 * TTLs for different content types.
 * 
 * Cache TTLs:
 * - Pages: 1 hour (3600 seconds)
 * - Policies: 1 hour (3600 seconds)
 * - Blogs: 30 minutes (1800 seconds)
 * - Articles: 30 minutes (1800 seconds)
 * 
 * Requirements: 10.4, 10.6, 10.7, 10.8, 10.9, 10.10
 */
class ContentService extends BaseService implements ContentServiceInterface
{
    use CacheWithFallback;
    /**
     * Policy type mapping from API types to Shopify policy fields
     */
    private const POLICY_TYPE_MAP = [
        'privacy' => 'privacyPolicy',
        'refund' => 'refundPolicy',
        'shipping' => 'shippingPolicy',
        'terms' => 'termsOfService',
    ];

    /**
     * Constructor
     * 
     * @param StorefrontApiClientInterface $storefrontClient Storefront API client for queries
     * @param ShopifyCacheStrategy $cacheStrategy Cache strategy for key generation
     */
    public function __construct(
        private readonly StorefrontApiClientInterface $storefrontClient,
        private readonly ShopifyCacheStrategy $cacheStrategy
    ) {
        parent::__construct();
    }

    /**
     * Get page by handle
     * 
     * Retrieves a Shopify page by its handle with 1-hour cache TTL.
     * Pages are static content like "About Us", "FAQ", etc.
     * 
     * @param string $handle Page handle (URL-friendly identifier)
     * @return PageDTO
     * @throws ShopifyApiException
     */
    public function getPageByHandle(string $handle): PageDTO
    {
        try {
            $this->logPerformanceStart('getPageByHandle');

            $page = $this->cacheWithFallback(
                $this->cacheStrategy->getCacheKey('page', ['handle' => $handle]),
                3600, // 1 hour
                fn() => $this->fetchPage($handle),
                ['page', $handle]
            );

            $this->logPerformanceEnd('getPageByHandle', [
                'handle' => $handle,
                'page_id' => $page->id,
            ]);

            return $page;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch page', $e, ['handle' => $handle]);
            throw $e;
        }
    }

    /**
     * Fetch page from Shopify API
     * 
     * @param string $handle Page handle
     * @return PageDTO
     * @throws ShopifyApiException
     */
    private function fetchPage(string $handle): PageDTO
    {
        $response = $this->storefrontClient->query('storefront/content/page_get', [
            'handle' => $handle,
        ]);

        if (empty($response['data']['pageByHandle'])) {
            throw new ShopifyNotFoundException("Page not found: {$handle}");
        }

        return PageDTO::fromShopifyResponse($response['data']['pageByHandle']);
    }

    /**
     * Get policy by type
     * 
     * Retrieves a Shopify policy page by type with 1-hour cache TTL.
     * Supported types: privacy, refund, shipping, terms
     * 
     * @param string $type Policy type (privacy, refund, shipping, terms)
     * @return PageDTO
     * @throws InvalidArgumentException If policy type is invalid
     * @throws ShopifyApiException
     */
    public function getPolicyByType(string $type): PageDTO
    {
        try {
            $this->logPerformanceStart('getPolicyByType');

            // Validate policy type
            if (!isset(self::POLICY_TYPE_MAP[$type])) {
                throw new InvalidArgumentException("Invalid policy type: {$type}. Supported types: " . implode(', ', array_keys(self::POLICY_TYPE_MAP)));
            }

            $policy = $this->cacheWithFallback(
                $this->cacheStrategy->getCacheKey('policy', ['type' => $type]),
                3600, // 1 hour
                fn() => $this->fetchPolicy($type),
                ['policy', $type]
            );

            $this->logPerformanceEnd('getPolicyByType', [
                'type' => $type,
                'policy_id' => $policy->id,
            ]);

            return $policy;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch policy', $e, ['type' => $type]);
            throw $e;
        }
    }

    /**
     * Fetch policy from Shopify API
     * 
     * @param string $type Policy type
     * @return PageDTO
     * @throws ShopifyApiException
     */
    private function fetchPolicy(string $type): PageDTO
    {
        $policyField = self::POLICY_TYPE_MAP[$type];

        $response = $this->storefrontClient->query('storefront/content/policy_get');

        if (empty($response['data']['shop'][$policyField])) {
            throw new ShopifyNotFoundException("Policy not found: {$type}");
        }

        return PageDTO::fromShopifyResponse($response['data']['shop'][$policyField]);
    }

    /**
     * Get blogs with pagination
     * 
     * Retrieves a list of blogs with 30-minute cache TTL.
     * Supports cursor-based pagination.
     * 
     * @param int $limit Number of blogs to retrieve (default: 10)
     * @param string|null $cursor Pagination cursor for next page
     * @return array ['items' => BlogDTO[], 'pagination' => ['has_next' => bool, 'next_cursor' => string|null]]
     * @throws ShopifyApiException
     */
    public function getBlogs(int $limit = 10, ?string $cursor = null): array
    {
        try {
            $this->logPerformanceStart('getBlogs');

            $cacheKey = $this->cacheStrategy->getCacheKey('blogs', [
                'limit' => $limit,
                'cursor' => $cursor,
            ]);

            $result = $this->cacheWithFallback(
                $cacheKey,
                1800, // 30 minutes
                fn() => $this->fetchBlogs($limit, $cursor),
                ['blogs']
            );

            $this->logPerformanceEnd('getBlogs', [
                'limit' => $limit,
                'cursor' => $cursor,
                'count' => count($result['items']),
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch blogs', $e, [
                'limit' => $limit,
                'cursor' => $cursor,
            ]);
            throw $e;
        }
    }

    /**
     * Fetch blogs from Shopify API
     * 
     * @param int $limit Number of blogs to retrieve
     * @param string|null $cursor Pagination cursor
     * @return array
     */
    private function fetchBlogs(int $limit, ?string $cursor): array
    {
        $response = $this->storefrontClient->query('storefront/content/blogs_list', [
            'limit' => $limit,
            'after' => $cursor,
        ]);

        $edges = $response['data']['blogs']['edges'] ?? [];
        $pageInfo = $response['data']['blogs']['pageInfo'] ?? [];

        return [
            'items' => array_map(
                fn($edge) => BlogDTO::fromShopifyResponse($edge['node']),
                $edges
            ),
            'pagination' => [
                'has_next' => $pageInfo['hasNextPage'] ?? false,
                'next_cursor' => $pageInfo['endCursor'] ?? null,
            ],
        ];
    }

    /**
     * Get articles for a blog with pagination
     * 
     * Retrieves articles for a specific blog with 30-minute cache TTL.
     * Supports cursor-based pagination.
     * 
     * @param string $blogHandle Blog handle
     * @param int $limit Number of articles to retrieve (default: 10)
     * @param string|null $cursor Pagination cursor for next page
     * @return array ['items' => ArticleDTO[], 'pagination' => ['has_next' => bool, 'next_cursor' => string|null]]
     * @throws ShopifyApiException
     */
    public function getArticles(string $blogHandle, int $limit = 10, ?string $cursor = null): array
    {
        try {
            $this->logPerformanceStart('getArticles');

            $cacheKey = $this->cacheStrategy->getCacheKey('articles', [
                'blog' => $blogHandle,
                'limit' => $limit,
                'cursor' => $cursor,
            ]);

            $result = $this->cacheWithFallback(
                $cacheKey,
                1800, // 30 minutes
                fn() => $this->fetchArticles($blogHandle, $limit, $cursor),
                ['articles', $blogHandle]
            );

            $this->logPerformanceEnd('getArticles', [
                'blog_handle' => $blogHandle,
                'limit' => $limit,
                'cursor' => $cursor,
                'count' => count($result['items']),
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch articles', $e, [
                'blog_handle' => $blogHandle,
                'limit' => $limit,
                'cursor' => $cursor,
            ]);
            throw $e;
        }
    }

    /**
     * Fetch articles from Shopify API
     * 
     * @param string $blogHandle Blog handle
     * @param int $limit Number of articles to retrieve
     * @param string|null $cursor Pagination cursor
     * @return array
     * @throws ShopifyApiException
     */
    private function fetchArticles(string $blogHandle, int $limit, ?string $cursor): array
    {
        $response = $this->storefrontClient->query('storefront/content/articles_list', [
            'blogHandle' => $blogHandle,
            'limit' => $limit,
            'after' => $cursor,
        ]);

        if (empty($response['data']['blog'])) {
            throw new ShopifyNotFoundException("Blog not found: {$blogHandle}");
        }

        $edges = $response['data']['blog']['articles']['edges'] ?? [];
        $pageInfo = $response['data']['blog']['articles']['pageInfo'] ?? [];

        return [
            'items' => array_map(
                fn($edge) => ArticleDTO::fromShopifyResponse($edge['node']),
                $edges
            ),
            'pagination' => [
                'has_next' => $pageInfo['hasNextPage'] ?? false,
                'next_cursor' => $pageInfo['endCursor'] ?? null,
            ],
        ];
    }

    /**
     * Get a single article
     * 
     * Retrieves a specific article by blog handle and article handle
     * with 30-minute cache TTL.
     * 
     * @param string $blogHandle Blog handle
     * @param string $articleHandle Article handle
     * @return ArticleDTO
     * @throws ShopifyApiException
     */
    public function getArticle(string $blogHandle, string $articleHandle): ArticleDTO
    {
        try {
            $this->logPerformanceStart('getArticle');

            $article = $this->cacheWithFallback(
                $this->cacheStrategy->getCacheKey('article', [
                    'blog' => $blogHandle,
                    'article' => $articleHandle,
                ]),
                1800, // 30 minutes
                fn() => $this->fetchArticle($blogHandle, $articleHandle),
                ['article', $blogHandle, $articleHandle]
            );

            $this->logPerformanceEnd('getArticle', [
                'blog_handle' => $blogHandle,
                'article_handle' => $articleHandle,
                'article_id' => $article->id,
            ]);

            return $article;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch article', $e, [
                'blog_handle' => $blogHandle,
                'article_handle' => $articleHandle,
            ]);
            throw $e;
        }
    }

    /**
     * Fetch article from Shopify API
     * 
     * @param string $blogHandle Blog handle
     * @param string $articleHandle Article handle
     * @return ArticleDTO
     * @throws ShopifyApiException
     */
    private function fetchArticle(string $blogHandle, string $articleHandle): ArticleDTO
    {
        $response = $this->storefrontClient->query('storefront/content/article_get', [
            'blogHandle' => $blogHandle,
            'articleHandle' => $articleHandle,
        ]);

        if (empty($response['data']['blog']['articleByHandle'])) {
            throw new ShopifyNotFoundException("Article not found: {$blogHandle}/{$articleHandle}");
        }

        return ArticleDTO::fromShopifyResponse($response['data']['blog']['articleByHandle']);
    }

    /**
     * Resolve URL to resource type
     * 
     * Parses a URL and determines the resource type and handle(s).
     * Useful for deep linking and URL-based navigation.
     * 
     * Supported URL patterns:
     * - /products/{handle} -> type: product, handle: {handle}
     * - /collections/{handle} -> type: collection, handle: {handle}
     * - /pages/{handle} -> type: page, handle: {handle}
     * - /blogs/{blog_handle} -> type: blog, blog_handle: {blog_handle}
     * - /blogs/{blog_handle}/{article_handle} -> type: article, blog_handle: {blog_handle}, article_handle: {article_handle}
     * - / -> type: home
     * 
     * @param string $url URL to resolve
     * @return array ['type' => string, 'handle' => string|null, 'blog_handle' => string|null, 'article_handle' => string|null]
     */
    public function resolveUrl(string $url): array
    {
        try {
            $this->logPerformanceStart('resolveUrl');

            // Parse URL and extract path
            $path = parse_url($url, PHP_URL_PATH);
            $segments = array_filter(explode('/', $path));

            // Home page
            if (count($segments) === 0) {
                $result = ['type' => 'home', 'handle' => null];
                $this->logPerformanceEnd('resolveUrl', ['url' => $url, 'type' => 'home']);
                return $result;
            }

            $firstSegment = $segments[array_key_first($segments)];
            $segments = array_values($segments); // Re-index array

            // Determine resource type based on first segment
            $result = match ($firstSegment) {
                'products' => [
                    'type' => 'product',
                    'handle' => $segments[1] ?? null,
                ],
                'collections' => [
                    'type' => 'collection',
                    'handle' => $segments[1] ?? null,
                ],
                'pages' => [
                    'type' => 'page',
                    'handle' => $segments[1] ?? null,
                ],
                'blogs' => [
                    'type' => count($segments) > 2 ? 'article' : 'blog',
                    'blog_handle' => $segments[1] ?? null,
                    'article_handle' => $segments[2] ?? null,
                ],
                default => [
                    'type' => 'unknown',
                    'handle' => null,
                ],
            };

            $this->logPerformanceEnd('resolveUrl', [
                'url' => $url,
                'type' => $result['type'],
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to resolve URL', $e, ['url' => $url]);
            throw $e;
        }
    }
}
