<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

use App\DTOs\Base\BaseDTO;

/**
 * Output of the IntentDetectionService — the detected intent label, a
 * confidence score, and any keywords lifted from the user's message for use
 * downstream (e.g., as Shopify product search input).
 */
class IntentDTO extends BaseDTO
{
    public const INTENT_PRODUCT_SUPPORT = 'product_support';

    public const INTENT_RECOMMENDATION = 'recommendation';

    public const INTENT_ORDER_TRACKING = 'order_tracking';

    public const INTENT_REFUND_POLICY = 'refund_policy';

    public const INTENT_SHIPPING_QUESTION = 'shipping_question';

    public const INTENT_CART_HELP = 'cart_help';

    public const INTENT_GREETING = 'greeting';

    public const INTENT_UPSELL_OPPORTUNITY = 'upsell_opportunity';

    public const INTENT_CROSS_SELL_OPPORTUNITY = 'cross_sell_opportunity';

    public const INTENT_UNKNOWN = 'unknown';

    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public readonly string $name,
        public readonly float $confidence,
        public readonly array $keywords,
        public readonly string $detectedBy,
    ) {
        $this->validate();
    }

    protected function validate(): void
    {
        $this->validateRequired($this->name, 'intent name');
        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new \InvalidArgumentException('confidence must be between 0.0 and 1.0');
        }
        $this->validateInArray($this->detectedBy, ['regex', 'classifier', 'fallback'], 'detectedBy');
    }
}
