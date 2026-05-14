<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

/**
 * Generates a concise summary of an ended conversation and writes it back
 * into the AiConversation `metadata.summary` field. Used by the admin
 * dashboard + by future conversation digests for support agents.
 *
 * Failure here MUST NOT block the customer — the summary is a nice-to-have.
 */
class GenerateConversationSummaryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(public readonly int $conversationId) {}

    public function handle(): void
    {
        $conversation = AiConversation::find($this->conversationId);
        if ($conversation === null) {
            return;
        }

        $messages = $conversation->messages()
            ->whereIn('role', [AiMessage::ROLE_USER, AiMessage::ROLE_ASSISTANT])
            ->orderBy('id')
            ->limit(40)
            ->get(['role', 'message'])
            ->map(fn (AiMessage $m): string => strtoupper($m->role).': '.$m->message)
            ->implode("\n");

        if ($messages === '') {
            return;
        }

        try {
            $response = OpenAI::chat()->create([
                'model' => (string) config('chatbot.models.default'),
                'temperature' => 0.2,
                'max_tokens' => 200,
                'messages' => [
                    ['role' => 'system', 'content' => 'Summarize this Shopify chat in <=80 words. Return plain text, no preamble.'],
                    ['role' => 'user', 'content' => $messages],
                ],
            ]);

            $summary = (string) ($response->choices[0]->message->content ?? '');
            if ($summary === '') {
                return;
            }

            $metadata = $conversation->metadata ?? [];
            $metadata['summary'] = $summary;
            $metadata['summary_generated_at'] = now()->toIso8601String();
            $conversation->update(['metadata' => $metadata]);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('Conversation summary failed', [
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('error')->error('GenerateConversationSummaryJob failed', [
            'conversation_id' => $this->conversationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
