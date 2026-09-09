<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\IntentDetectionServiceInterface;
use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\IntentDTO;
use App\Services\Base\BaseService;
use Illuminate\Support\Facades\Cache;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

/**
 * Hybrid intent detection. A fast keyword/regex pass classifies the obvious
 * cases (greeting, order tracking, refund/shipping questions, recommendation
 * verbs). When confidence falls below the configured threshold, the message
 * is forwarded to OpenAI with JSON-mode for a low-cost classifier call.
 *
 * Intent results for identical messages are cached for 5 minutes to avoid
 * repeating classifier calls during retries / SSE reconnects.
 */
class IntentDetectionService extends BaseService implements IntentDetectionServiceInterface
{
    /**
     * @var array<string, array<int, string>>
     */
    private const KEYWORDS = [
        IntentDTO::INTENT_GREETING => [
            'hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening',
        ],
        IntentDTO::INTENT_ORDER_TRACKING => [
            'track my order', 'order status', 'where is my order', 'shipment', 'delivery date',
        ],
        IntentDTO::INTENT_REFUND_POLICY => [
            'refund', 'return policy', 'return item', 'money back', 'exchange',
        ],
        IntentDTO::INTENT_SHIPPING_QUESTION => [
            'shipping', 'delivery time', 'how long to ship', 'ship to', 'shipping cost',
        ],
        IntentDTO::INTENT_CART_HELP => [
            'cart', 'checkout', 'discount code', 'coupon', 'apply promo',
        ],
        IntentDTO::INTENT_RECOMMENDATION => [
            'recommend', 'suggest', 'best ', 'looking for', 'find me', 'show me',
        ],
        IntentDTO::INTENT_PRODUCT_SUPPORT => [
            'how does', 'is this', 'does it', 'compatible', 'waterproof', 'specs', 'specifications',
        ],
    ];

    public function __construct(
        private readonly ChatbotConfigRepository $chatbotConfig,
    ) {
        parent::__construct();
    }

    public function detect(string $message, ChatContextDTO $context): IntentDTO
    {
        $normalized = mb_strtolower(trim($message));
        // Cache key must include cart/product fingerprint — context priors
        // change the result for the same message text.
        $cacheKey = 'ai:intent:'.md5(implode('|', [
            $normalized,
            $context->pageType,
            $context->product?->id ?? '',
            $context->cart?->totalPrice ?? '',
            (string) ($context->cart?->itemCount ?? 0),
        ]));
        $ttl = (int) config('chatbot.intent.cache_ttl', 300);

        $cached = Cache::get($cacheKey);
        if ($cached instanceof IntentDTO) {
            return $cached;
        }

        $fastPath = $this->fastPath($normalized, $context);
        $threshold = (float) config('chatbot.intent.confidence_threshold', 0.65);

        $result = $fastPath->confidence >= $threshold ? $fastPath : $this->classifierFallback($message, $context, $fastPath);

        // Context priors (Phase C) — applied AFTER fast-path/classifier so a
        // strong keyword signal still wins. Only escalates known intents to
        // the sales-oriented variants when the cart context supports it.
        $result = $this->applySalesContextPriors($result, $context);

        Cache::put($cacheKey, $result, $ttl);

        return $result;
    }

    /**
     * Promote product_support -> cross_sell_opportunity when a cart exists
     * and does not contain the viewed product (proxy for "shopping for
     * something else").
     *
     * Returns the input unchanged when no prior fires.
     */
    private function applySalesContextPriors(IntentDTO $detected, ChatContextDTO $context): IntentDTO
    {
        if ($detected->name === IntentDTO::INTENT_PRODUCT_SUPPORT
            && $context->cart !== null
            && ! $context->cart->isEmpty()
            && $context->product !== null
            && $context->product->id !== null
            && ! $this->cartContainsProduct($context->cart->items, $context->product->id)
        ) {
            return new IntentDTO(
                name: IntentDTO::INTENT_CROSS_SELL_OPPORTUNITY,
                confidence: $this->chatbotConfig->intentCrossSellPriorConfidence(),
                keywords: $detected->keywords,
                detectedBy: $detected->detectedBy,
            );
        }

        return $detected;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cartItems
     */
    private function cartContainsProduct(array $cartItems, string $productId): bool
    {
        foreach ($cartItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (string) ($item['product_id'] ?? $item['id'] ?? '');
            if ($id === $productId) {
                return true;
            }
        }

        return false;
    }

    private function fastPath(string $normalized, ChatContextDTO $context): IntentDTO
    {
        // Page-type prior: product page implies product_support unless contradicted.
        $defaultIntent = match ($context->pageType) {
            'product' => IntentDTO::INTENT_PRODUCT_SUPPORT,
            'cart' => IntentDTO::INTENT_CART_HELP,
            'account' => IntentDTO::INTENT_ORDER_TRACKING,
            default => IntentDTO::INTENT_UNKNOWN,
        };

        // Order matters: more specific intents come AFTER greetings here, but
        // greetings use tiny tokens that collide with normal vocabulary
        // ("hi" inside "this"), so we score intents and pick the most
        // specific match (longest keyword) rather than first-match-wins.
        $bestIntent = null;
        $bestKeyword = '';
        foreach (self::KEYWORDS as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                $pattern = '/(?<!\w)'.preg_quote($keyword, '/').'(?!\w)/u';
                if (preg_match($pattern, $normalized) === 1 && mb_strlen($keyword) > mb_strlen($bestKeyword)) {
                    $bestIntent = $intent;
                    $bestKeyword = $keyword;
                }
            }
        }

        if ($bestIntent !== null) {
            return new IntentDTO(
                name: $bestIntent,
                confidence: $this->chatbotConfig->intentFastpathConfidence(),
                keywords: [$bestKeyword],
                detectedBy: 'regex',
            );
        }

        return new IntentDTO(
            name: $defaultIntent,
            confidence: $defaultIntent === IntentDTO::INTENT_UNKNOWN
                ? $this->chatbotConfig->intentUnknownDefaultConfidence()
                : $this->chatbotConfig->intentPageTypeDefaultConfidence(),
            keywords: [],
            detectedBy: 'regex',
        );
    }

    private function classifierFallback(string $message, ChatContextDTO $context, IntentDTO $hint): IntentDTO
    {
        $supported = (array) config('chatbot.intent.supported', []);
        $model = (string) config('chatbot.models.classifier');

        try {
            $response = OpenAI::chat()->create([
                'model' => $model,
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You classify Shopify storefront chat messages into a single intent label. '
                            .'Reply with strict JSON: {"intent":"<one of: '.implode(',', $supported).'>","confidence":0.0-1.0}. '
                            .'Use "unknown" if the message does not match any intent.',
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'message' => $message,
                            'page_type' => $context->pageType,
                            'hint' => $hint->name,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ],
            ]);

            $raw = $response->choices[0]->message->content ?? '{}';
            $decoded = json_decode((string) $raw, true);
            $intent = is_array($decoded) ? (string) ($decoded['intent'] ?? IntentDTO::INTENT_UNKNOWN) : IntentDTO::INTENT_UNKNOWN;
            $confidence = is_array($decoded)
                ? (float) ($decoded['confidence'] ?? $this->chatbotConfig->intentMalformedFallbackConfidence())
                : $this->chatbotConfig->intentErrorFallbackMinConfidence();

            if (! in_array($intent, $supported, true)) {
                $intent = IntentDTO::INTENT_UNKNOWN;
            }

            return new IntentDTO(
                name: $intent,
                confidence: max(0.0, min(1.0, $confidence)),
                keywords: $hint->keywords,
                detectedBy: 'classifier',
            );
        } catch (Throwable $e) {
            $this->logWarning('Intent classifier fallback failed; using hint.', [
                'error' => $e->getMessage(),
            ], 'ai');

            return new IntentDTO(
                name: $hint->name,
                confidence: max($this->chatbotConfig->intentErrorFallbackMinConfidence(), $hint->confidence),
                keywords: $hint->keywords,
                detectedBy: 'fallback',
            );
        }
    }
}
