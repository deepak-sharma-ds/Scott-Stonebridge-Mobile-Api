<?php

declare(strict_types=1);

namespace App\Contracts\Services\AI;

use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\IntentDTO;

interface ShopifyContextServiceInterface
{
    /**
     * Resolve any data the frontend context is missing but the AI requires
     * to answer the current intent (live product, cart, policy, customer
     * orders). Uses existing AdminApiClient / StorefrontApiClient under the
     * hood and applies short-TTL caching.
     *
     * @return array<string, mixed> Structured context ready to inject into the prompt.
     */
    public function resolve(ChatContextDTO $context, IntentDTO $intent, ?string $accessToken = null): array;
}
