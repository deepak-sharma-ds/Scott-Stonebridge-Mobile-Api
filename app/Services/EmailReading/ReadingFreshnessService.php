<?php

declare(strict_types=1);

namespace App\Services\EmailReading;

use App\Models\EmailReadingDelivery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

/**
 * The Freshness Protocol: keeps repeat customers from receiving recycled
 * readings without sending their old readings back to the model.
 *
 * Only a compact theme line goes into the prompt; a Reading Embedding of each
 * completed reading is stored and compared by cosine similarity.
 */
class ReadingFreshnessService
{
    private const MAJOR_ARCANA = 'Fool|Magician|High Priestess|Empress|Emperor|Hierophant|Lovers|Chariot|Strength|Hermit|Wheel of Fortune|Justice|Hanged Man|Death|Temperance|Devil|Tower|Star|Moon|Sun|Judgement|World';

    private const MINOR_ARCANA = '(?:Ace|Two|Three|Four|Five|Six|Seven|Eight|Nine|Ten|Page|Knight|Queen|King) of (?:Cups|Wands|Swords|Pentacles)';

    /** Word cap for one reading's summary and for the combined prompt line. */
    private const SUMMARY_WORDS = 15;

    private const LINE_WORDS = 30;

    private const CLOSING_HEADING = 'from my heart';

    /**
     * The customer's earlier readings that still carry a stored response,
     * newest first. Includes readings generated but not yet emailed, so two
     * orders inside the send window still see each other.
     *
     * @return Collection<int,EmailReadingDelivery>
     */
    public function previousReadings(EmailReadingDelivery $delivery): Collection
    {
        return EmailReadingDelivery::query()
            ->whereRaw('LOWER(customer_email) = ?', [mb_strtolower(trim((string) $delivery->customer_email))])
            ->whereKeyNot($delivery->getKey())
            ->whereIn('status', [
                EmailReadingDelivery::STATUS_GENERATED,
                EmailReadingDelivery::STATUS_SENDING,
                EmailReadingDelivery::STATUS_SENT,
            ])
            ->whereNotNull('ai_response')
            ->where('ai_response', '!=', '')
            ->latest('id')
            ->limit(max(1, (int) config('email_reading.freshness.history_limit', 5)))
            ->get();
    }

    /**
     * The compact line appended to a returning customer's prompt, or null for
     * a first-time customer.
     */
    public function themeLine(EmailReadingDelivery $delivery): ?string
    {
        $items = [];

        foreach ($this->previousReadings($delivery) as $previous) {
            $summary = filled($previous->theme_summary)
                ? (string) $previous->theme_summary
                : $this->extractThemeSummary((string) $previous->ai_response);

            foreach (array_filter(array_map('trim', explode(',', $summary))) as $item) {
                $items[mb_strtolower($item)] ??= $item;
            }
        }

        $themes = $this->limitWords(array_values($items), self::LINE_WORDS);

        if ($themes === '') {
            return null;
        }

        return 'Previous reading for this client explored: '.$themes.'. Provide fresh cards, distinct perspectives, and progressive guidance.';
    }

    /**
     * A short comma-separated list of the cards a reading names, falling back
     * to its section headings. Extracted locally so no extra model call is
     * needed.
     */
    public function extractThemeSummary(string $html): string
    {
        $plain = $this->plainText($html);

        preg_match_all('/\b(?:The (?:'.self::MAJOR_ARCANA.')|'.self::MINOR_ARCANA.')\b/', $plain, $matches);
        $items = array_values(array_unique($matches[0]));

        if ($items === []) {
            preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $html, $headings);

            $items = array_values(array_filter(array_map(
                fn (string $heading): string => trim(html_entity_decode(strip_tags($heading))),
                $headings[1]
            ), fn (string $heading): bool => $heading !== '' && ! str_contains(mb_strtolower($heading), self::CLOSING_HEADING)));
        }

        return mb_substr($this->limitWords($items, self::SUMMARY_WORDS), 0, 255);
    }

    /**
     * Embed the reading's plain text. Fails open: an embeddings outage must
     * never fail a delivery, it only skips the freshness check.
     *
     * @return list<float>|null
     */
    public function embed(string $html): ?array
    {
        $text = mb_substr($this->plainText($html), 0, 24000);

        if ($text === '') {
            return null;
        }

        try {
            $response = OpenAI::embeddings()->create([
                'model' => (string) config('email_reading.freshness.embedding_model', 'text-embedding-3-small'),
                'input' => $text,
            ]);

            $vector = $response->embeddings[0]->embedding ?? null;

            return is_array($vector) && $vector !== [] ? array_map('floatval', $vector) : null;
        } catch (Throwable $e) {
            Log::channel('shopify_webhooks')->warning('Reading embedding unavailable; freshness check skipped', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Highest cosine similarity between a vector and the stored embeddings of
     * the given readings (0.0 when none has one).
     *
     * @param  list<float>  $embedding
     * @param  Collection<int,EmailReadingDelivery>  $previous
     */
    public function highestSimilarity(array $embedding, Collection $previous): float
    {
        return (float) $previous
            ->map(fn (EmailReadingDelivery $reading): float => $this->cosine($embedding, (array) $reading->embedding))
            ->max();
    }

    /**
     * Cosine similarity of two equal-length vectors; 0.0 for empty,
     * mismatched or zero-magnitude input.
     *
     * @param  array<int,float|int>  $a
     * @param  array<int,float|int>  $b
     */
    public function cosine(array $a, array $b): float
    {
        if ($a === [] || count($a) !== count($b)) {
            return 0.0;
        }

        $dot = $normA = $normB = 0.0;

        foreach ($a as $i => $value) {
            $other = (float) $b[$i];
            $dot += $value * $other;
            $normA += $value * $value;
            $normB += $other * $other;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    private function plainText(string $html): string
    {
        $text = (string) preg_replace('/<[^>]+>/', ' ', $html);

        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode($text)));
    }

    /**
     * Join items with ", " while staying inside a word budget.
     *
     * @param  list<string>  $items
     */
    private function limitWords(array $items, int $maxWords): string
    {
        $kept = [];
        $words = 0;

        foreach ($items as $item) {
            $count = str_word_count($item);

            if ($kept !== [] && $words + $count > $maxWords) {
                break;
            }

            $kept[] = $item;
            $words += $count;
        }

        return implode(', ', $kept);
    }
}
