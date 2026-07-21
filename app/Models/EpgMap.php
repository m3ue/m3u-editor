<?php

namespace App\Models;

use App\Enums\Status;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EpgMap extends Model
{
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'processing' => 'boolean',
        'candidates_building' => 'boolean',
        // 'override' => 'boolean',
        'progress' => 'float',
        'user_id' => 'integer',
        'epg_id' => 'integer',
        'channel_count' => 'integer',
        'mapped_count' => 'integer',
        'settings' => 'array',
        'channels' => 'array',
        'group_ids' => 'array',
        'status' => Status::class,
        'candidates_built_at' => 'datetime',
        'candidates_progress' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function epg(): BelongsTo
    {
        return $this->belongsTo(Epg::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(EpgMapCandidate::class);
    }
}
