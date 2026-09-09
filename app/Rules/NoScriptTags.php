<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class NoScriptTags implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Simple check for the presence of '<script' (case-insensitive)
        if (is_string($value) && preg_match('/<script/i', $value)) {
            $fail('The :attribute field cannot contain script tags.');
        }
    }
}
