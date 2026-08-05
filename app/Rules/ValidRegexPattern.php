<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidRegexPattern implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * Filament's TagsInput attaches field rules to the whole array state, so
     * $value here is the full list of entered tags rather than a single one.
     * Each entry is checked independently using the same delimiter escaping
     * ProcessM3uImport applies at runtime, so a pattern accepted here is
     * guaranteed to compile during import.
     *
     * @param  Closure(string, ?string=):PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (Arr::wrap($value) as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            if (! self::compiles($pattern)) {
                $fail("The pattern \"{$pattern}\" is not a valid regular expression.");
            }
        }
    }

    protected static function compiles(string $pattern): bool
    {
        $delimiter = '/';
        $escaped = str_replace($delimiter, '\\'.$delimiter, $pattern);
        $finalPattern = $delimiter.$escaped.$delimiter.'u';

        return @preg_match($finalPattern, '') !== false;
    }
}
