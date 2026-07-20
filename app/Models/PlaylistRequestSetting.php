<?php

namespace App\Models;

use App\Traits\HasPolymorphicPlaylistOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaylistRequestSetting extends Model
{
    use HasFactory;
    use HasPolymorphicPlaylistOwner;

    protected function casts(): array
    {
        return [
            'playlist_id' => 'integer',
            'custom_playlist_id' => 'integer',
            'merged_playlist_id' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
