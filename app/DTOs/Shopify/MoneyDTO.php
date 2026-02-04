<?php

declare(strict_types=1);

namespace App\DTOs\Shopify;

use App\DTOs\BaseDTO;

final readonly class MoneyDTO extends BaseDTO
{
    public function __construct(
        public float $amount,
        public string $currencyCode,
    ) {}
    
    /**
     * Create from Shopify money object
     */
    public static function fromShopifyMoney(array $money): self
    {
        return new self(
            amount: (float) $money['amount'],
            currencyCode: $money['currencyCode'],
        );
    }
    
    /**
     * Get formatted money string
     */
    public function formatted(): string
    {
        return number_format($this->amount, 2) . ' ' . $this->currencyCode;
    }
}
