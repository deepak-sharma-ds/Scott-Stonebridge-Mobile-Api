<?php

declare(strict_types=1);

namespace App\Services\EmailReading;

use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

/**
 * Pre-generation Compliance Gate for customer questions.
 *
 * Screens the answers a customer submitted for critical compliance breaches
 * (medical diagnosis/prognosis, prescriptions, pregnancy outcomes, self-harm,
 * criminal outcomes, gambling/financial picks) BEFORE any reading is
 * generated. Zero marginal cost: local patterns first, then the free OpenAI
 * Moderation endpoint. A Moderation outage fails open (patterns only) so it
 * can never block orders.
 */
class ComplianceGateService
{
    /**
     * Category label => case-insensitive pattern.
     *
     * @var array<string,string>
     */
    private const PATTERNS = [
        'Medical diagnosis or prognosis' => '/\b(?:diagnos(?:e|is|ed|ing)|prognosis|(?:do|does|did) (?:i|he|she|they|my \w+) (?:have|got) (?:cancer|a tumou?r|leuk(?:a)?emia|diabetes|hiv|dementia|alzheimer\w*|multiple sclerosis)|is (?:it|this|that) (?:cancer|a tumou?r|serious)|will (?:i|he|she|they|my \w+) (?:survive|recover from|beat|die from|be cured)|(?:cure|treatment) for (?:my|his|her|their)|should (?:i|he|she) (?:stop|come off|increase|reduce|skip) (?:taking )?(?:my |his |her )?(?:medication|meds|tablets|pills|treatment|chemo\w*))\b/i',
        'Prescription or medication advice' => '/\b(?:prescri(?:be|bed|ption)|dosage|what (?:medication|medicine|drug|tablets?|pills?) should|which (?:medication|medicine|drug|tablets?|pills?))\b/i',
        'Pregnancy medical outcome' => '/\b(?:am i pregnant|miscarr\w+|stillbirth|(?:will|is|going to be) (?:my|the|our) (?:baby|pregnancy|unborn)\b.{0,30}\b(?:healthy|okay|ok|survive|born)|baby\b.{0,20}\bhealthy|pregnancy complications?)\b/i',
        'Self-harm or suicide' => '/\b(?:kill(?:ing)? myself|end(?:ing)? my (?:own )?life|end it all|take my own life|suicid\w*|self[- ]harm\w*|hurt(?:ing)? myself|want to die|don\'?t want to (?:live|be here)|better off (?:dead|without me)|cut(?:ting)? myself)\b/i',
        'Criminal accusation or legal outcome' => '/\b(?:found guilty|be convicted|be sentenced|go to (?:prison|jail)|win (?:my|the) (?:court|trial|case)|(?:court|trial) (?:outcome|verdict)|who (?:killed|murdered|stole|raped)|did (?:he|she|they) (?:kill|murder|steal|rape|abuse)|is (?:he|she) (?:guilty|innocent))\b/i',
        'Gambling or financial pick' => '/\b(?:lottery|lotto|euromillions|winning numbers|jackpot|scratch ?cards?|bet(?:ting)? on|gambl\w+|which (?:horse|team|stocks?|shares?)|stock market|bitcoin|crypto\w*|should i invest|invest(?:ing)? in)\b/i',
    ];

    /**
     * Evaluate the customer's submitted answers.
     *
     * @param  array<string,mixed>  $questions  the delivery `questions` snapshot
     * @return string|null the human-readable flagged reason, or null when clean
     */
    public function evaluate(array $questions): ?string
    {
        $text = $this->answerText($questions);

        if ($text === '') {
            return null;
        }

        foreach (self::PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                return $label.': "'.trim($matches[0]).'"';
            }
        }

        return $this->moderationReason($text);
    }

    /**
     * Every answer value (skipping the `_raw` audit copy and `qN` aliases'
     * duplicates), joined into one block.
     *
     * @param  array<string,mixed>  $questions
     */
    private function answerText(array $questions): string
    {
        $values = [];

        foreach ($questions as $key => $value) {
            if ($key === '_raw' || ! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $values[$value] = $value;
            }
        }

        return implode("\n", $values);
    }

    private function moderationReason(string $text): ?string
    {
        try {
            $response = OpenAI::moderations()->create([
                'model' => (string) config('email_reading.compliance.moderation_model', 'omni-moderation-latest'),
                'input' => $text,
            ]);
        } catch (Throwable $e) {
            Log::channel('shopify_webhooks')->warning('Compliance moderation unavailable; relying on local patterns', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $blocked = (array) config('email_reading.compliance.moderation_categories', []);
        $violated = [];

        foreach ($response->results as $result) {
            foreach ($result->categories as $name => $category) {
                if ($category->violated && in_array($name, $blocked, true)) {
                    $violated[$name] = $name;
                }
            }
        }

        return $violated === []
            ? null
            : 'Flagged by content moderation: '.implode(', ', $violated);
    }
}
