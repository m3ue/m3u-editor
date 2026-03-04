<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function streamFileSetting(): BelongsTo
    {
        return $this->belongsTo(StreamFileSetting::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function enabled_channels(): HasMany
    {
        return $this->hasMany(Channel::class)
            ->where('enabled', true);
    }

    public function live_channels(): HasMany
    {
        return $this->channels()
            ->where('is_vod', false);
    }

    public function enabled_live_channels(): HasMany
    {
        return $this->live_channels()
            ->where('enabled', true);
    }

    public function vod_channels(): HasMany
    {
        return $this->channels()
            ->where('is_vod', true);
    }

    public function enabled_vod_channels(): HasMany
    {
        return $this->vod_channels()
            ->where('enabled', true);
    }
}
