<?php

namespace App\Models;

use App\Services\AssetFilePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class Asset extends Model
{
    use HasFactory;

    protected $casts = [
        'size_bytes' => 'integer',
        'is_image' => 'boolean',
        'last_modified_at' => 'datetime',
    ];

    protected $appends = [
        'preview_url',
    ];

    public function getPreviewUrlAttribute(): string
    {
        return route('assets.preview', $this);
    }

    public function inlinePreviewMimeType(): ?string
    {
        $disk = Storage::disk($this->disk);
        if (! $disk->exists($this->path)) {
            return null;
        }

        // Flysystem's detector can fall back to the extension for text/plain.
        // Use the actual local file bytes, never a filename-based fallback.
        $mimeType = File::mimeType($disk->path($this->path)) ?: null;

        return AssetFilePolicy::allows($this->path, $mimeType, imagesOnly: true) ? $mimeType : null;
    }

    public function isInlinePreviewable(): bool
    {
        return $this->inlinePreviewMimeType() !== null;
    }
}
