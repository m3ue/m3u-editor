<?php

namespace App\Models;

use Database\Factories\ManagedCacheInterestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedCacheInterest extends Model
{
    /** @use HasFactory<ManagedCacheInterestFactory> */
    use HasFactory;

    protected $fillable = [
        'cached_content_file_id',
        'integration_id',
        'mapping_uuid',
        'canonical_id',
        'variant_key',
        'consumer_id',
        'watched_at',
        'release_after',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'watched_at' => 'datetime',
            'release_after' => 'datetime',
        ];
    }

    public function cachedContentFile(): BelongsTo
    {
        return $this->belongsTo(CachedContentFile::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(MediaServerIntegration::class);
    }
}
