<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidDateFormat implements ValidationRule
{
    // Known PHP date format characters
    private const FORMAT_CHARS = 'YymdjnJDlNwzWFMtLoGgHhisaABZUcru';

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=):PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            $fail('The :attribute must not be empty.');

            return;
        }

        if (strlen($value) > 100) {
            $fail('The :attribute must not exceed 100 characters.');

            return;
        }

        // Ensure it contains at least one recognised PHP date format character
        if (! preg_match('/['.self::FORMAT_CHARS.']/', $value)) {
            $fail('The :attribute must contain at least one valid PHP date format character (e.g. Y, m, d, H, i, s).');

            return;
        }

        // Attempt to format a known date to confirm no errors
        try {
            $result = date($value, mktime(14, 30, 0, 1, 15, 2024));
            if ($result === false) {
                $fail('The :attribute is not a valid PHP date format string.');
            }
        } catch (\Throwable) {
            $fail('The :attribute is not a valid PHP date format string.');
        }
    }
}
