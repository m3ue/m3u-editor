<?php

namespace App\Rules;

use App\Settings\GeneralSettings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class UrlIsAllowed implements ValidationRule
{
    /**
     * Create a new rule instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=):PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Ensure valid URL format and allowed domain if url is provided
        if (str_starts_with($value, 'http')) {
            // Config env value takes priority; fall back to GeneralSettings
            $configDomains = config('dev.allowed_playlist_domains');
            if ($configDomains && ! empty($configDomains)) {
                $validUrls = array_map('trim', explode(',', $configDomains));
            } else {
                $settingUrls = app(GeneralSettings::class)->allowed_urls;
                $validUrls = ! empty($settingUrls) ? $settingUrls : null;
            }

            if ($validUrls && ! empty($validUrls)) {
                $isValid = false;
                foreach ($validUrls as $validUrl) {
                    // Convert wildcard to regex
                    $regex = str_replace('\*', '.*', preg_quote($validUrl, '/'));
                    if (preg_match('/^'.$regex.'$/i', $value)) {
                        $isValid = true;
                        break;
                    }
                }
                if (! $isValid) {
                    $fail('URL is not an allowed domain. Please enter a URL from an allowed domains list.');
                }
            }
        }
    }
}
