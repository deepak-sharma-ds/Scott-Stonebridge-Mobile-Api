<?php

declare(strict_types=1);

namespace App\Services\EmailReading;

use App\Models\EmailReadingDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

/**
 * Writes the History Digest of a sent delivery: a few sentences on what the
 * customer was told, built from the response exactly as it was sent so admin
 * edits are reflected. Used later as Customer History context.
 */
class HistoryDigestService
{
    /** Characters of the reading sent to the model (a reading is about 1,500 words). */
    private const MAX_SOURCE_CHARS = 12000;

    public function isEnabled(): bool
    {
        return (bool) config('email_reading.history.digest.enabled', true);
    }

    /**
     * Write and store the digest unless one already exists.
     *
     * Never overwrites an existing digest (automatic or hand-written): the
     * save is a conditional UPDATE, so a digest written by an admin while the
     * model was running also wins.
     *
     * @return bool true when a digest was stored
     *
     * @throws RuntimeException when OpenAI returns nothing (so the job retries)
     */
    public function write(EmailReadingDelivery $delivery): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $delivery->refresh();

        if ($delivery->status !== EmailReadingDelivery::STATUS_SENT || filled($delivery->history_digest)) {
            return false;
        }

        $source = $this->plainText((string) $delivery->ai_response);

        if ($source === '') {
            return false;
        }

        $digest = $this->summarise($source, (string) $delivery->product?->name);

        $saved = EmailReadingDelivery::query()
            ->whereKey($delivery->id)
            ->where('status', EmailReadingDelivery::STATUS_SENT)
            ->where(fn ($query) => $query->whereNull('history_digest')->orWhere('history_digest', ''))
            ->update([
                'history_digest' => $digest,
                'history_digest_generated_at' => Carbon::now(),
                'history_digest_is_manual' => false,
            ]);

        if ($saved !== 1) {
            Log::channel('shopify_webhooks')->info('History digest written but delivery changed; result discarded', [
                'delivery_id' => $delivery->id,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Admin action: store a hand-written digest on a sent delivery (the email
     * itself stays frozen). A blank text removes the digest, so the delivery
     * contributes its answers only. Never touches the delivery's status.
     *
     * @throws RuntimeException when the delivery is not `sent`
     */
    public function saveManual(EmailReadingDelivery $delivery, ?string $text): void
    {
        $this->assertCanHaveDigest($delivery);

        $text = trim((string) $text);

        EmailReadingDelivery::query()
            ->whereKey($delivery->id)
            ->where('status', EmailReadingDelivery::STATUS_SENT)
            ->update($text === ''
                ? ['history_digest' => null, 'history_digest_generated_at' => null, 'history_digest_is_manual' => false]
                : ['history_digest' => $text, 'history_digest_generated_at' => Carbon::now(), 'history_digest_is_manual' => true]);

        $delivery->refresh();
    }

    /**
     * Admin action: write the digest again from the response as sent,
     * replacing any existing one (the controller asks for confirmation before
     * replacing a hand-written digest). Runs even when automatic digests are
     * switched off. OpenAI failures propagate so the admin sees them.
     *
     * @throws RuntimeException when the delivery is not `sent`, has no response, or OpenAI returns nothing
     */
    public function regenerate(EmailReadingDelivery $delivery): void
    {
        $this->assertCanHaveDigest($delivery);

        $source = $this->plainText((string) $delivery->ai_response);

        if ($source === '') {
            throw new RuntimeException('This reading has no response to summarise.');
        }

        $digest = $this->summarise($source, (string) $delivery->product?->name);

        EmailReadingDelivery::query()
            ->whereKey($delivery->id)
            ->where('status', EmailReadingDelivery::STATUS_SENT)
            ->update([
                'history_digest' => $digest,
                'history_digest_generated_at' => Carbon::now(),
                'history_digest_is_manual' => false,
            ]);

        $delivery->refresh();
    }

    /**
     * @throws RuntimeException
     */
    private function assertCanHaveDigest(EmailReadingDelivery $delivery): void
    {
        $delivery->refresh();

        if ($delivery->status === EmailReadingDelivery::STATUS_SENDING) {
            throw new RuntimeException('This reading is being sent right now. Try again in a minute.');
        }

        if ($delivery->status !== EmailReadingDelivery::STATUS_SENT) {
            throw new RuntimeException('Only a sent reading has a history digest.');
        }
    }

    private function summarise(string $reading, string $productName): string
    {
        $user = ($productName !== '' ? "Product: {$productName}\n\n" : '')."Reading:\n".mb_substr($reading, 0, self::MAX_SOURCE_CHARS);

        $response = OpenAI::chat()->create([
            'model' => (string) config('email_reading.history.digest.model', 'gpt-4.1-mini'),
            'messages' => [
                ['role' => 'system', 'content' => (string) config('email_reading.history.digest.instructions')],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => 0.2,
            'max_tokens' => (int) config('email_reading.history.digest.max_tokens', 250),
        ]);

        $digest = trim((string) ($response->choices[0]->message->content ?? ''));

        if ($digest === '') {
            throw new RuntimeException('OpenAI returned an empty history digest.');
        }

        return $digest;
    }

    private function plainText(string $html): string
    {
        $text = (string) preg_replace('/<[^>]+>/', ' ', $html);

        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode($text)));
    }
}
