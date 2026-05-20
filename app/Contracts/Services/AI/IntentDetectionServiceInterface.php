<?php

declare(strict_types=1);

namespace App\Contracts\Services\AI;

use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\IntentDTO;

interface IntentDetectionServiceInterface
{
    /**
     * Classify a user message into one of the supported intents. Implementations
     * may fall back to an OpenAI classifier when fast-path regex confidence is
     * below the configured threshold.
     */
    public function detect(string $message, ChatContextDTO $context): IntentDTO;
}
