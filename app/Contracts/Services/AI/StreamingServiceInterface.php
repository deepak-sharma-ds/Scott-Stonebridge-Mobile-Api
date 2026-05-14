<?php

declare(strict_types=1);

namespace App\Contracts\Services\AI;

use App\DTOs\Chat\ChatRequestDTO;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface StreamingServiceInterface
{
    /**
     * Build a Symfony StreamedResponse that pipes OpenAI streamed completion
     * chunks to the client as Server-Sent Events. Headers + heartbeat are set
     * on the response so controllers can return it directly.
     */
    public function stream(ChatRequestDTO $request): StreamedResponse;
}
