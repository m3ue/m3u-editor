<?php

namespace App\Models;

use Database\Factories\ManagedCacheEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedCacheEvent extends Model
{
    /** @use HasFactory<ManagedCacheEventFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'integration_id',
        'action',
        'request_id',
        'payload_hash',
        'redacted_response',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_id' => 'string',
            'redacted_response' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(MediaServerIntegration::class);
    }
}
