<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NoScriptTags implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Simple check for the presence of '<script' (case-insensitive)
        if (is_string($value) && preg_match('/<script/i', $value)) {
            $fail('The :attribute field cannot contain script tags.');
        }
    }
}
