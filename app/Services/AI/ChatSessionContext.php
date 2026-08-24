<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\DTOs\Chat\CartContextDTO;

/**
 * Lightweight value object carrying the per-turn session envelope into the
 * tool-call layer. Built from the inbound request + the AiConversation row.
 */
final class ChatSessionContext
{
    /**
     * @param  array<string, true>  $shownVariantIds  Variant ids already
     *                                                visible to the model this session — seeded from persisted
     *                                                history at turn start, extended as this turn's own tool
     *                                                results are collected. See ADR 0010.
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $shopDomain,
        public readonly ?string $cartId = null,
        public readonly ?string $customerAccessToken = null,
        public readonly string $locale = 'en',
        public readonly ?string $pageType = null,
        public readonly ?CartContextDTO $cartSnapshot = null,
        public readonly array $shownVariantIds = [],
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
            cartSnapshot: $this->cartSnapshot,
            shownVariantIds: $this->shownVariantIds,
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
            cartSnapshot: $this->cartSnapshot,
            shownVariantIds: $this->shownVariantIds,
        );
    }

    public function withCartSnapshot(?CartContextDTO $cartSnapshot): self
    {
        return new self(
            sessionId: $this->sessionId,
            shopDomain: $this->shopDomain,
            cartId: $this->cartId,
            customerAccessToken: $this->customerAccessToken,
            locale: $this->locale,
            pageType: $this->pageType,
            cartSnapshot: $cartSnapshot,
            shownVariantIds: $this->shownVariantIds,
        );
    }

    /**
     * @param  array<string, true>  $additional
     */
    public function withAdditionalShownVariantIds(array $additional): self
    {
        if ($additional === []) {
            return $this;
        }

        return new self(
            sessionId: $this->sessionId,
            shopDomain: $this->shopDomain,
            cartId: $this->cartId,
            customerAccessToken: $this->customerAccessToken,
            locale: $this->locale,
            pageType: $this->pageType,
            cartSnapshot: $this->cartSnapshot,
            shownVariantIds: $this->shownVariantIds + $additional,
        );
    }
}
