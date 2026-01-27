<?php

namespace App\Http\Controllers\Apis;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Facades\Shopify;
use App\Traits\ShopifyResponseFormatter;
use Illuminate\Support\Facades\Validator;

class AboutPageController extends Controller
{
    use ShopifyResponseFormatter;

    /**
     * Get page details by handle (example: about-me)
     */
    public function getPageDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'handle' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->fail('Validation error.', $validator->errors());
        }

        try {
            $vars = [
                'handle' => trim($request->input('handle')),
            ];

            $response = Shopify::query(
                'storefront',
                'pages/get_about_page_details',
                $vars
            );

            $page = data_get($response, 'data.page');

            if (!$page) {
                return response()->json([
                    'status' => false,
                    'message' => 'Page not found',
                    'data' => null,
                ], 404);
            }

            $originalBody = $page['body'] ?? '';

            // 1) Extract Lets Connect full data (links + message + image)
            $letsConnectData = $this->extractLetsConnectData($originalBody);

            // 2) Remove Lets Connect section from body (so it doesn’t appear in normal sections)
            $bodyWithoutLetsConnect = $this->removeLetsConnectSection($originalBody);

            // 3) Build section-wise data (Intro + Strong headings)
            $sections = [];

            $intro = $this->extractIntroSection($bodyWithoutLetsConnect);
            if ($intro) {
                $sections[] = $intro;
            }

            $sections = array_merge(
                $sections,
                $this->extractSectionsByStrongHeadings($bodyWithoutLetsConnect)
            );

            // 4) Remove raw HTML body (optional, recommended for RN)
            unset($page['body']);

            // 5) Attach structured output
            $page['sections'] = $sections;

            $page['lets_connect'] = [
                'title'     => "Let's Connect",
                'links'     => $letsConnectData['links'],
                'message'   => $letsConnectData['message'],
                'image_url' => $letsConnectData['image_url'],
            ];

            return $this->success('Page details fetched successfully', $page);
        } catch (\Throwable $e) {
            return $this->fail('Failed to fetch page details', $e->getMessage());
        }
    }

    /**
     * Extract INTRO section before first heading marker
     * This fixes missing top content like:
     * "Who is Scottstonebridge?", "Scott Stonebridge", "Empowering Your Journey..."
     */
    private function extractIntroSection(string $html): ?array
    {
        if (!$html) return null;

        // Find the first major section heading marker
        $patterns = [
            '/<p>\s*<strong>/i',
            '/<strong>/i',
            '/<h1[^>]*>/i',
            '/<h2[^>]*>/i',
            '/<h3[^>]*>/i',
        ];

        $firstPos = null;

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                if ($firstPos === null || $pos < $firstPos) {
                    $firstPos = $pos;
                }
            }
        }

        if ($firstPos === null) {
            $firstPos = strlen($html);
        }

        $introHtml = trim(substr($html, 0, $firstPos));

        // If intro is empty return null
        if (!$introHtml || strlen(trim(strip_tags($introHtml))) < 5) {
            return null;
        }

        return [
            'title'  => 'Intro',
            'text'   => $this->cleanText($introHtml),
            'images' => $this->extractImages($introHtml),
            'html'   => $introHtml,
        ];
    }

    /**
     * Split page HTML into sections based on <strong>Heading</strong>
     */
    private function extractSectionsByStrongHeadings(string $html): array
    {
        if (!$html) return [];

        preg_match_all(
            '/<strong>(.*?)<\/strong>(.*?)(?=<strong>|$)/is',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $sections = [];

        foreach ($matches as $m) {
            $title = trim(strip_tags($m[1]));
            $contentHtml = trim($m[2]);

            if (!$title) continue;

            $sections[] = [
                'title'  => html_entity_decode($title),
                'text'   => $this->cleanText($contentHtml),
                'images' => $this->extractImages($contentHtml),
                'html'   => $contentHtml,
            ];
        }

        return $sections;
    }

    /**
     * Extract Lets Connect block: links + message + image
     */
    private function extractLetsConnectData(string $html): array
    {
        if (!$html) {
            return [
                'links' => [],
                'message' => null,
                'image_url' => null,
            ];
        }

        // Find Lets Connect heading
        $start = stripos($html, "<strong>Let’s Connect</strong>");
        if ($start === false) {
            $start = stripos($html, "<strong>Let's Connect</strong>");
        }

        if ($start === false) {
            return [
                'links' => [],
                'message' => null,
                'image_url' => null,
            ];
        }

        // Take chunk from Lets Connect onwards
        $chunk = substr($html, $start);

        // Extract UL links
        $links = [];
        $ulHtml = null;

        if (preg_match('/<ul\b[^>]*>(.*?)<\/ul>/is', $chunk, $ulMatch)) {
            $ulHtml = $ulMatch[0];

            preg_match_all('/<a\s+[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/i', $ulHtml, $matches, PREG_SET_ORDER);

            foreach ($matches as $m) {
                $href = trim($m[1]);
                $text = trim(strip_tags($m[2]));

                if (!$href || !$text) continue;

                $links[] = [
                    'title'  => html_entity_decode($text),
                    'url'    => $href,
                    'type'   => $this->detectShopifyLinkType($href),
                    'handle' => $this->extractHandleFromUrl($href),
                ];
            }
        }

        // Remove UL from chunk to get message + image area
        $afterUl = $chunk;
        if ($ulHtml) {
            $afterUl = str_replace($ulHtml, '', $chunk);
        }

        // Extract image inside Lets Connect chunk
        $imageUrl = null;
        if (preg_match('/<img[^>]+src="([^"]+)"/i', $afterUl, $imgMatch)) {
            $imageUrl = trim($imgMatch[1]);
        }

        // Extract message text
        $message = trim(strip_tags($afterUl));
        $message = html_entity_decode($message);
        $message = preg_replace("/\n\s*\n+/", "\n\n", $message);

        // Remove heading text from message
        $message = str_replace(["Let’s Connect", "Let's Connect"], '', $message);
        $message = trim($message);

        return [
            'links' => $links,
            'message' => $message ?: null,
            'image_url' => $imageUrl,
        ];
    }

    /**
     * Remove Lets Connect section from body
     */
    private function removeLetsConnectSection(string $html): string
    {
        if (!$html) return $html;

        $start = stripos($html, "<strong>Let’s Connect</strong>");
        if ($start === false) {
            $start = stripos($html, "<strong>Let's Connect</strong>");
        }

        if ($start === false) return $html;

        return substr($html, 0, $start);
    }

    /**
     * Detect link type for app navigation
     */
    private function detectShopifyLinkType(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        if (str_starts_with($path, '/products/')) return 'product';
        if (str_starts_with($path, '/collections/')) return 'collection';
        if (str_starts_with($path, '/pages/')) return 'page';
        if (str_starts_with($path, '/blogs/')) return 'blog';

        return 'external';
    }

    /**
     * Extract handle from Shopify URL
     */
    private function extractHandleFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        foreach (['/products/', '/collections/', '/pages/', '/blogs/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $handle = substr($path, strlen($prefix));
                $handle = trim($handle, '/');
                return $handle ?: null;
            }
        }

        return null;
    }

    /**
     * Extract images from any HTML chunk
     */
    private function extractImages(string $html): array
    {
        $images = [];
        preg_match_all('/<img[^>]+src="([^"]+)"/i', $html, $matches);

        foreach ($matches[1] ?? [] as $src) {
            $images[] = trim($src);
        }

        return array_values(array_unique($images));
    }

    /**
     * Clean HTML -> plain text
     */
    private function cleanText(string $html): string
    {
        $text = trim(strip_tags($html));
        $text = html_entity_decode($text);
        $text = preg_replace("/\n\s*\n+/", "\n\n", $text);
        return $text;
    }
}
