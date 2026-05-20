<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persists chatbot analytics events. Writes a daily-rotated log line through
 * the `ai` channel for now — once a dedicated `ai_analytics_events` table
 * lands in a follow-up migration, the handle() body switches to an Eloquent
 * insert without changing the dispatch sites.
 */
class StoreAnalyticsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $event,
        public readonly string $sessionId,
        public readonly array $payload = [],
    ) {}

    public function handle(): void
    {
        Log::channel('ai')->info('ai.analytics', [
            'event' => $this->event,
            'session_id' => $this->sessionId,
            'payload' => $this->payload,
            'recorded_at' => now()->toIso8601String(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('error')->error('StoreAnalyticsJob failed', [
            'event' => $this->event,
            'session_id' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
