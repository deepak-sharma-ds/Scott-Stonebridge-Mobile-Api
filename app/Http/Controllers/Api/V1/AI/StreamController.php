<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\AI;

use App\Contracts\Services\AI\StreamingServiceInterface;
use App\DTOs\Chat\ChatRequestDTO;
use App\Exceptions\AI\AIException;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\AI\StreamMessageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Server-Sent Events streaming endpoint. The Symfony StreamedResponse must be
 * returned directly — it bypasses the JSON envelope. Errors that occur BEFORE
 * the stream begins are returned as the normal API error envelope so the
 * frontend can show a useful message instead of an empty event stream.
 */
class StreamController extends BaseApiController
{
    public function __construct(
        private readonly StreamingServiceInterface $streaming,
    ) {}

    public function stream(StreamMessageRequest $request, string $session): JsonResponse|StreamedResponse
    {
        // Defensive: route param is unconstrained; reject anything that
        // isn't a UUID so the self-heal adoption path can't be tricked
        // into creating rows keyed on garbage.
        if (! Str::isUuid($session)) {
            return $this->error('Invalid session id.', [], ['error_code' => 'invalid_session_id'], 422);
        }

        $data = $request->validated();
        $dto = ChatRequestDTO::fromArray([
            'session_id' => $session,
            'message' => $data['message'],
            'context' => $data['context'] ?? [],
            'access_token' => $request->bearerToken(),
            'ip_address' => $request->ip(),
        ]);

        try {
            return $this->streaming->stream($dto);
        } catch (AIException $e) {
            return $this->error($e->getMessage(), $e->errorContext(), ['error_code' => $e->errorCode()], $e->httpStatus());
        } catch (Throwable $e) {
            report($e);

            return $this->error('AI stream failed.', [], ['error_code' => 'ai_stream_failed'], 500);
        }
    }
}
