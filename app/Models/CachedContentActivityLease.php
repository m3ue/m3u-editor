<?php

namespace App\Models;

use Database\Factories\CachedContentActivityLeaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CachedContentActivityLease extends Model
{
    /** @use HasFactory<CachedContentActivityLeaseFactory> */
    use HasFactory;

    protected $fillable = [
        'cached_content_file_id',
        'user_id',
        'integration_id',
        'mapping_uuid',
        'revision',
        'canonical_id',
        'variant_key',
        'kind',
        'lease_id',
        'sequence',
        'expires_at',
        'last_activity_at',
        'terminal_until',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'expires_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'terminal_until' => 'datetime',
        ];
    }
}
