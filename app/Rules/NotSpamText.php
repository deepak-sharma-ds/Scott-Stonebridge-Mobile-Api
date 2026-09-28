<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Rejects low-effort spam text: a single character repeated ("dddddddd"),
 * or free text with too little character variety to be a genuine message
 * (e.g. "aaaaaaaabbbbbbbb").
 */
class NotSpamText implements ValidationRule
{
    private const MIN_LENGTH_FOR_DIVERSITY_CHECK = 8;

    private const MIN_UNIQUE_CHAR_RATIO = 0.25;

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return;
        }

        if (preg_match('/(.)\1{4,}/us', $normalized)) {
            $fail('The :attribute looks like spam. Please provide a real message.');

            return;
        }

        $letters = preg_replace('/\s+/u', '', mb_strtolower($normalized));
        $length = mb_strlen((string) $letters);

        if ($length >= self::MIN_LENGTH_FOR_DIVERSITY_CHECK) {
            $uniqueCount = count(array_unique(mb_str_split((string) $letters)));
            if (($uniqueCount / $length) < self::MIN_UNIQUE_CHAR_RATIO) {
                $fail('The :attribute looks like spam. Please provide a real message.');
            }
        }
    }
}
