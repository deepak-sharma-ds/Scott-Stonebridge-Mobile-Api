<?php

namespace App\Jobs\EmailReading;

use App\Models\EmailReadingDelivery;
use App\Services\EmailReading\EmailReadingGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessEmailReadingOrderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(EmailReadingGenerationService $service): void
    {
        /** @var EmailReadingDelivery|null $delivery */
        $delivery = EmailReadingDelivery::with('product')->find($this->deliveryId);

        if (! $delivery) {
            Log::channel('shopify_webhooks')->warning('Delivery not found in processing job', [
                'delivery_id' => $this->deliveryId,
            ]);

            return;
        }

        if ($delivery->status !== EmailReadingDelivery::STATUS_PENDING) {
            return;
        }

        $delivery->increment('attempts');

        $missing = $this->missingRequiredQuestions($delivery);
        if (! empty($missing)) {
            $message = 'Missing required questions: '.implode(', ', $missing);
            $delivery->markFailed($message);

            NotifyReadingFailureJob::dispatch($delivery->id, $message)
                ->onConnection(config('email_reading.queue.connection'))
                ->onQueue(config('email_reading.queue.mail'));

            Log::channel('shopify_webhooks')->warning('Reading delivery missing required questions', [
                'delivery_id' => $delivery->id,
                'missing' => $missing,
            ]);

            return;
        }

        try {
            $service->generate($delivery);
        } catch (Throwable $e) {
            throw $e;
        }

        SendEmailReadingJob::dispatch($delivery->id)
            ->onConnection(config('email_reading.queue.connection'))
            ->onQueue(config('email_reading.queue.mail'));
    }

    public function failed(Throwable $e): void
    {
        $delivery = EmailReadingDelivery::find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        $delivery->markFailed($e->getMessage());

        NotifyReadingFailureJob::dispatch($delivery->id, $e->getMessage())
            ->onConnection(config('email_reading.queue.connection'))
            ->onQueue(config('email_reading.queue.mail'));
    }

    /**
     * Returns the labels of any required schema slots that have no value in
     * the captured `questions` snapshot.
     *
     * A slot is considered present when ANY of these lookups resolves to a
     * non-empty string:
     *   1. normalised `key` from the schema row
     *   2. normalised `label` from the schema row (covers cases where the
     *      Shopify property name matches the customer-facing label rather
     *      than the internal key)
     *   3. positional alias `q{n}` based on the slot's order in the schema
     *
     * This mirrors how `ShopifyReadingWebhookController::mapProperties()`
     * writes the questions snapshot, so any reasonable property naming on
     * the Shopify side resolves without code changes.
     *
     * @return array<int,string>
     */
    private function missingRequiredQuestions(EmailReadingDelivery $delivery): array
    {
        $schema = (array) ($delivery->product?->questions_schema ?? []);
        $questions = (array) $delivery->questions;
        $missing = [];
        $position = 0;

        foreach ($schema as $slot) {
            $position++;
            if (! (bool) ($slot['required'] ?? false)) {
                continue;
            }

            $label = (string) ($slot['label'] ?? '');
            $keyRaw = (string) ($slot['key'] ?? '');
            $candidates = [
                EmailReadingDelivery::normalizeKey($keyRaw),
                EmailReadingDelivery::normalizeKey($label),
                'q'.$position,
            ];

            $resolved = false;
            foreach ($candidates as $candidate) {
                if ($candidate === '') {
                    continue;
                }
                $value = trim((string) ($questions[$candidate] ?? ''));
                if ($value !== '') {
                    $resolved = true;
                    break;
                }
            }

            if (! $resolved) {
                $missing[] = $label !== '' ? $label : $keyRaw;
            }
        }

        return $missing;
    }
}
