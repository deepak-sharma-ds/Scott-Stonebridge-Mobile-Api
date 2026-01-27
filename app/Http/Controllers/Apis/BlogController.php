<?php

namespace App\Http\Controllers\Apis;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Facades\Shopify;
use App\Traits\ShopifyResponseFormatter;
use Illuminate\Support\Facades\Validator;

class BlogController extends Controller
{
    use ShopifyResponseFormatter;

    /**
     * GET /api/blog/details?handle=news&first=10&after=CURSOR(optional)
     */
    public function getBlogDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'handle' => 'required|string',
            'first'  => 'nullable|integer|min:1|max:50',
            'after'  => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->fail('Validation error.', $validator->errors());
        }

        try {
            $vars = [
                'handle' => trim($request->input('handle')),
                'first'  => (int)($request->input('first', 10)),
                'after'  => $request->input('after') ? trim($request->input('after')) : null,
            ];

            $response = Shopify::query('storefront', 'blogs/get_blog_details', $vars);

            $blog = data_get($response, 'data.blog');

            if (!$blog) {
                return response()->json([
                    'status' => false,
                    'message' => 'Blog not found',
                    'data' => null,
                ], 404);
            }

            $articlesConn = $blog['articles'] ?? [];
            $edges = $articlesConn['edges'] ?? [];

            $articles = [];
            foreach ($edges as $edge) {
                $node = $edge['node'] ?? null;
                if (!$node) continue;

                $contentHtml = $node['contentHtml'] ?? '';
                $featuredImage = $node['image'] ?? null;
                $fallbackImage = $this->extractFirstImageFromHtml($contentHtml);
                $image = $featuredImage ?: $fallbackImage;

                $articles[] = [
                    'id'          => $node['id'] ?? null,
                    'title'       => $node['title'] ?? null,
                    'handle'      => $node['handle'] ?? null,
                    'excerpt'     => $node['excerpt'] ?? null,
                    'publishedAt' => $node['publishedAt'] ?? null,
                    'author'      => data_get($node, 'authorV2.name'),
                    'image'       => $image ?? null,

                    // CONTENT ONLY (not raw HTML)
                    'content' => [
                        'blocks' => $this->htmlToBlocks($contentHtml),
                    ],
                ];
            }

            $data = [
                'id'     => $blog['id'] ?? null,
                'title'  => $blog['title'] ?? null,
                'handle' => $blog['handle'] ?? null,
                'articles' => $articles,
                'pageInfo' => [
                    'hasNextPage' => (bool) data_get($articlesConn, 'pageInfo.hasNextPage', false),
                    'endCursor'   => data_get($articlesConn, 'pageInfo.endCursor'),
                ],
            ];

            return $this->success('Blog details fetched successfully', $data);
        } catch (\Throwable $e) {
            return $this->fail('Failed to fetch blog details', $e->getMessage());
        }
    }

    /**
     * GET /api/blog/article?blog_handle=news&article_handle=some-article
     */
    public function getArticleDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'blog_handle'    => 'required|string',
            'article_handle' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->fail('Validation error.', $validator->errors());
        }

        try {
            $vars = [
                'blogHandle'    => trim($request->input('blog_handle')),
                'articleHandle' => trim($request->input('article_handle')),
            ];

            $response = Shopify::query('storefront', 'blogs/get_blog_article_details', $vars);

            $blog = data_get($response, 'data.blog');
            $article = data_get($response, 'data.blog.articleByHandle');

            if (!$blog || !$article) {
                return response()->json([
                    'status' => false,
                    'message' => 'Article not found',
                    'data' => null,
                ], 404);
            }

            $contentHtml = $article['contentHtml'] ?? '';
            $featuredImage = $article['image'] ?? null;
            $fallbackImage = $this->extractFirstImageFromHtml($contentHtml);
            $image = $featuredImage ?: $fallbackImage;

            $data = [
                'blog' => [
                    'id'     => $blog['id'] ?? null,
                    'title'  => $blog['title'] ?? null,
                    'handle' => $blog['handle'] ?? null,
                ],
                'article' => [
                    'id'          => $article['id'] ?? null,
                    'title'       => $article['title'] ?? null,
                    'handle'      => $article['handle'] ?? null,
                    'excerpt'     => $article['excerpt'] ?? null,
                    'publishedAt' => $article['publishedAt'] ?? null,
                    'author'      => data_get($article, 'authorV2.name'),
                    'image'       => $image ?? null,

                    // CONTENT ONLY (not raw HTML)
                    'content' => [
                        'blocks' => $this->htmlToBlocks($contentHtml),
                    ],
                ],
            ];

            return $this->success('Article fetched successfully', $data);
        } catch (\Throwable $e) {
            return $this->fail('Failed to fetch article', $e->getMessage());
        }
    }

    private function extractFirstImageFromHtml(string $html): ?array
    {
        if (trim($html) === '') return null;

        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>');

        $imgs = $dom->getElementsByTagName('img');
        if ($imgs->length === 0) return null;

        $img = $imgs->item(0);

        return [
            'url' => $img->getAttribute('src') ?: null,
            'altText' => $img->getAttribute('alt') ?: null,
        ];
    }

    /**
     * GET /api/blog/resolve?url=/products/abc
     * Frontend clicks a link -> send url here -> backend tells what screen to open.
     */
    public function resolveUrl(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'url' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->fail('Validation error.', $validator->errors());
        }

        $url = trim($request->input('url'));

        $resolved = $this->resolveInternalUrl($url);

        return $this->success('Resolved', $resolved);
    }

    // =========================================================
    // URL RESOLVER (dynamic routing for internal links)
    // =========================================================

    private function resolveInternalUrl(string $url): array
    {
        $normalized = $this->normalizeUrl($url);
        $path = $normalized['path'];

        // /blogs/{blog}/{article?}
        if (preg_match('#^/blogs/([^/]+)(?:/([^/]+))?#i', $path, $m)) {
            $blogHandle = $m[1] ?? null;
            $articleHandle = $m[2] ?? null;

            if ($blogHandle && $articleHandle) {
                return [
                    'type' => 'blog_article',
                    'blog_handle' => $blogHandle,
                    'article_handle' => $articleHandle,
                    'url' => $normalized['raw'],
                ];
            }

            return [
                'type' => 'blog',
                'handle' => $blogHandle,
                'url' => $normalized['raw'],
            ];
        }

        // /collections/{collection}/products/{product}
        if (preg_match('#^/collections/([^/]+)/products/([^/]+)#i', $path, $m)) {
            return [
                'type' => 'product',
                'handle' => $m[2],
                'collection_handle' => $m[1],
                'url' => $normalized['raw'],
            ];
        }

        // /products/{handle}
        if (preg_match('#^/products/([^/]+)#i', $path, $m)) {
            return [
                'type' => 'product',
                'handle' => $m[1],
                'url' => $normalized['raw'],
            ];
        }

        // /collections/{handle}
        if (preg_match('#^/collections/([^/]+)#i', $path, $m)) {
            return [
                'type' => 'collection',
                'handle' => $m[1],
                'url' => $normalized['raw'],
            ];
        }

        // /pages/{handle}
        if (preg_match('#^/pages/([^/]+)#i', $path, $m)) {
            return [
                'type' => 'page',
                'handle' => $m[1],
                'url' => $normalized['raw'],
            ];
        }

        // cart/checkout -> force external
        if ($path === '/cart' || str_starts_with($path, '/checkout')) {
            return [
                'type' => 'external',
                'url' => $normalized['raw'],
            ];
        }

        return [
            'type' => 'external',
            'url' => $normalized['raw'],
        ];
    }

    private function normalizeUrl(string $url): array
    {
        $raw = trim($url);

        // relative like "products/abc" => "/products/abc"
        if (!str_starts_with($raw, 'http') && !str_starts_with($raw, '/')) {
            $raw = '/' . $raw;
        }

        $parsed = parse_url($raw);
        $path = '/' . ltrim($parsed['path'] ?? '/', '/');

        return [
            'raw' => $raw,
            'path' => $path,
        ];
    }

    // =========================================================
    // HTML -> BLOCKS (CONTENT ONLY)
    // =========================================================

    /**
     * Converts Shopify HTML into blocks that frontend can render with custom UI.
     * Output format:
     * [
     *   { type: "heading", level: 2, text: "..." },
     *   { type: "paragraph", spans: [ {type:"text",text:"..."}, {type:"link",text:"...",url:"/products/x"} ] }
     * ]
     */
    private function htmlToBlocks(string $html): array
    {
        $html = trim($html);
        if ($html === '') return [];

        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>');

        $wrapper = $dom->getElementsByTagName('div')->item(0);
        if (!$wrapper) return [];

        $blocks = [];

        foreach ($wrapper->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = trim($node->textContent);
                if ($text !== '') {
                    $blocks[] = [
                        'type' => 'paragraph',
                        'spans' => [
                            ['type' => 'text', 'text' => $text],
                        ],
                    ];
                }
                continue;
            }

            if ($node->nodeType !== XML_ELEMENT_NODE) continue;

            $tag = strtolower($node->nodeName);

            // Headings
            if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {
                $level = (int) str_replace('h', '', $tag);
                $blocks[] = [
                    'type' => 'heading',
                    'level' => $level,
                    'text' => trim($node->textContent),
                ];
                continue;
            }

            // Paragraph
            if ($tag === 'p') {
                $spans = $this->nodeToSpans($node);
                if (!empty($spans)) {
                    $blocks[] = [
                        'type' => 'paragraph',
                        'spans' => $spans,
                    ];
                }
                continue;
            }

            // Image
            if ($tag === 'img') {
                $src = $node->getAttribute('src');
                if ($src) {
                    $blocks[] = [
                        'type' => 'image',
                        'src' => $src,
                        'alt' => $node->getAttribute('alt') ?: null,
                    ];
                }
                continue;
            }

            // Lists
            if (in_array($tag, ['ul', 'ol'])) {
                $items = [];
                foreach ($node->getElementsByTagName('li') as $li) {
                    $items[] = $this->nodeToSpans($li);
                }
                if (!empty($items)) {
                    $blocks[] = [
                        'type' => 'list',
                        'ordered' => ($tag === 'ol'),
                        'items' => $items,
                    ];
                }
                continue;
            }

            // Fallback: treat unknown tag as paragraph text
            $fallbackText = trim($node->textContent);
            if ($fallbackText !== '') {
                $blocks[] = [
                    'type' => 'paragraph',
                    'spans' => [
                        ['type' => 'text', 'text' => $fallbackText],
                    ],
                ];
            }
        }

        return $blocks;
    }

    /**
     * Convert a DOM node into spans (text + links).
     * This is the key: frontend knows EXACTLY which text is clickable.
     */
    private function nodeToSpans(\DOMNode $node): array
    {
        $spans = [];

        foreach ($node->childNodes as $child) {
            // plain text
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = $this->cleanText($child->textContent);
                if ($text !== '') {
                    $spans[] = ['type' => 'text', 'text' => $text];
                }
                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) continue;

            $tag = strtolower($child->nodeName);

            // link
            if ($tag === 'a') {
                $href = trim($child->getAttribute('href'));
                $text = $this->cleanText($child->textContent);

                if ($href && $text !== '') {
                    $spans[] = [
                        'type' => 'link',
                        'text' => $text,
                        'url' => $href,
                    ];
                } else {
                    // if no href or empty text -> fallback as text
                    $fallback = $this->cleanText($child->textContent);
                    if ($fallback !== '') {
                        $spans[] = ['type' => 'text', 'text' => $fallback];
                    }
                }
                continue;
            }

            // strong/em/span/etc -> flatten as text (or recursively parse)
            $nestedText = $this->cleanText($child->textContent);
            if ($nestedText !== '') {
                $spans[] = ['type' => 'text', 'text' => $nestedText];
            }
        }

        // merge adjacent text spans to reduce noise
        $merged = [];
        foreach ($spans as $span) {
            $lastIndex = count($merged) - 1;
            if ($span['type'] === 'text' && $lastIndex >= 0 && $merged[$lastIndex]['type'] === 'text') {
                $merged[$lastIndex]['text'] .= $span['text'];
            } else {
                $merged[] = $span;
            }
        }

        return $merged;
    }

    private function cleanText(?string $text): string
    {
        $text = html_entity_decode($text ?? '');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
