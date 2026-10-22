<?php

namespace App\Rules;

use App\Services\AssetFilePolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

class AllowedAssetFile implements ValidationRule
{
    public function __construct(private readonly bool $imagesOnly = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // FileUpload also validates persisted paths when editing a setting.
        // They are not new uploads; its path-tampering guard owns those values.
        if (! $value instanceof UploadedFile) {
            return;
        }

        $path = $value->getRealPath();
        $mimeType = is_string($path) && is_file($path) ? File::mimeType($path) : null;
        if (! AssetFilePolicy::allows($value->getClientOriginalName(), $mimeType ?: null, $this->imagesOnly)
            || (AssetFilePolicy::allows($value->getClientOriginalName(), $mimeType ?: null, imagesOnly: true)
                && @getimagesize($path) === false)) {
            $fail(__('assets.invalid_upload'));
        }
    }
}
