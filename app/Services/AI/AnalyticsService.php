<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\AnalyticsServiceInterface;
use App\Jobs\AI\StoreAnalyticsJob;
use App\Services\Base\BaseService;
use Throwable;

/**
 * Thin facade in front of the analytics queue. Every event is dispatched
 * asynchronously so the request path never waits on persistence.
 */
class AnalyticsService extends BaseService implements AnalyticsServiceInterface
{
    public function record(string $event, string $sessionId, array $payload = []): void
    {
        try {
            StoreAnalyticsJob::dispatch($event, $sessionId, $payload)
                ->onConnection((string) config('chatbot.queue.connection', 'redis'))
                ->onQueue((string) config('chatbot.queue.name', 'ai'));
        } catch (Throwable $e) {
            // Never let analytics dispatch failure break the chat flow.
            $this->logWarning('Analytics dispatch failed', [
                'event' => $event,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ], 'ai');
        }
    }
}
