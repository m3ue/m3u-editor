<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'disk',
        'path',
        'source',
        'name',
        'extension',
        'mime_type',
        'size_bytes',
        'is_image',
        'last_modified_at',
    ];

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
}
