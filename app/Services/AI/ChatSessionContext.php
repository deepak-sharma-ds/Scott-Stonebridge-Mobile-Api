<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Lightweight value object carrying the per-turn session envelope into the
 * tool-call layer. Built from the inbound request + the AiConversation row.
 */
final class ChatSessionContext
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $shopDomain,
        public readonly ?string $cartId = null,
        public readonly ?string $customerAccessToken = null,
        public readonly string $locale = 'en',
        public readonly ?string $pageType = null,
    ) {}

    public function withCustomerAccessToken(?string $token): self
    {
        return new self(
            sessionId: $this->sessionId,
            shopDomain: $this->shopDomain,
            cartId: $this->cartId,
            customerAccessToken: $token,
            locale: $this->locale,
            pageType: $this->pageType,
        );
    }

    public function withCartId(?string $cartId): self
    {
        return new self(
            sessionId: $this->sessionId,
            shopDomain: $this->shopDomain,
            cartId: $cartId,
            customerAccessToken: $this->customerAccessToken,
            locale: $this->locale,
            pageType: $this->pageType,
        );
    }
}
