<?php

namespace App\Models;

use App\Enums\WatchHistoryProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Trakt or SIMKL account the server sends watch progress to on behalf of
 * the Playlist Auths attached to it (every viewer signed in with one).
 */
class WatchHistoryIntegration extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => WatchHistoryProvider::class,
            'enabled' => 'boolean',
            'push_progress' => 'boolean',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_pushed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlistAuths(): BelongsToMany
    {
        return $this->belongsToMany(PlaylistAuth::class, 'playlist_auth_watch_history_integration')
            ->withTimestamps();
    }

    public function syncStates(): HasMany
    {
        return $this->hasMany(WatchHistorySyncState::class);
    }

    public function isConnected(): bool
    {
        return filled($this->access_token);
    }

    /**
     * Enabled, signed in, and set to receive progress as it's recorded.
     */
    public function scopePushing(Builder $query): Builder
    {
        return $query->where('enabled', true)
            ->where('push_progress', true)
            ->whereNotNull('access_token');
    }

    /**
     * Forget the tokens after the provider rejected them, keeping the row
     * and its Playlist Auths so a reconnect picks up where it left off.
     */
    public function markSignedOut(string $reason): void
    {
        $this->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'last_error' => $reason,
        ])->save();
    }

    /**
     * Forget the sign-in at the user's request, keeping the row and its
     * Playlist Auths so they can reconnect later.
     */
    public function disconnect(): void
    {
        $this->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'account_name' => null,
            'last_error' => null,
        ])->save();
    }
}
