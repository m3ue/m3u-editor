<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

class CheckIfUrlOrLocalPath implements ValidationRule
{
    /**
     * Create a new rule instance.
     */
    public function __construct(
        protected ?bool $urlOnly = false,
        protected ?bool $localOnly = false,
        protected ?bool $isDirectory = false,
    ) {
        //
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=):PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->urlOnly && $this->localOnly) {
            $fail('Cannot be both a URL and a local path');
        }
        if ($this->urlOnly && ! str_starts_with($value, 'http')) {
            $fail('Must be a valid URL');
        }
        if ($this->localOnly && str_starts_with($value, 'http')) {
            $fail('Must be a valid local file path');
        }

        // Check if url and valid, or is a valid local file path
        if (str_starts_with($value, 'http')) {
            if (! Str::isUrl($value)) {
                $fail('Must be a valid url');
            }
        } else {
            if ($this->isDirectory) {
                // Check if directory exists
                if (! is_dir($value)) {
                    $fail('Must be a valid directory path, unable to locate directory at the provided path.');
                }
            } else {
                // Check if file exists
                if (! file_exists($value)) {
                    $fail('Must be a valid file path, unable to locate file at the provided path.');
                }
            }
        }
    }
}
