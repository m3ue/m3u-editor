<?php

namespace App\Rules;

use Closure;
use Cron\CronExpression;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class Cron implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=):PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! CronExpression::isValidExpression($value)) {
            $fail('The :attribute must be a valid CRON expression.');

            return;
        }

        // isValidExpression() only checks syntax; a syntactically valid expression can
        // still have no calendar occurrence (e.g. "0 0 31 2 *", a February 31st), which
        // getNextRunDate() rejects at run time. Reject it here too, rather than silently
        // persisting a schedule that can never actually fire.
        try {
            (new CronExpression($value))->getNextRunDate();
        } catch (\RuntimeException) {
            $fail('The :attribute is syntactically valid but never occurs on a calendar.');
        }
    }
}
