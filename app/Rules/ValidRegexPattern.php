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
        return @preg_match(self::compile($pattern), '') !== false;
    }

    /**
     * Wrap a delimiter-less user pattern the same way the import pipeline does.
     */
    public static function compile(string $pattern, string $flags = 'u'): string
    {
        $delimiter = '/';

        return $delimiter.str_replace($delimiter, '\\'.$delimiter, $pattern).$delimiter.$flags;
    }
}
