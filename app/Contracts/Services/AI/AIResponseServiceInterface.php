<?php

declare(strict_types=1);

namespace App\Contracts\Services\AI;

use App\DTOs\Chat\AIResponseDTO;
use App\DTOs\Chat\IntentDTO;
use App\DTOs\Chat\ProductRecommendationDTO;

interface AIResponseServiceInterface
{
    /**
     * Non-streamed completion.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<ProductRecommendationDTO>  $products
     */
    public function complete(array $messages, IntentDTO $intent, array $products = []): AIResponseDTO;
}
