<?php

namespace App\Models;

use App\Services\TvClientConfigService;
use App\Support\TvClientSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable M3U TV app configuration. Playlist Auths pick one; the owner's
 * default profile covers their own login, alias logins and auths without one.
 */
class TvClientProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'is_default',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'is_default' => 'boolean',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TvClientProfile $profile): void {
            $profile->user_id ??= auth()->id();
        });

        static::saving(function (TvClientProfile $profile): void {
            $profile->settings = TvClientSettings::normalize($profile->settings);
        });

        // One default per owner: marking a profile default clears the others.
        // getOriginal() still holds the pre-save values inside `saved`.
        static::saved(function (TvClientProfile $profile): void {
            if ($profile->is_default && ! $profile->getOriginal('is_default')) {
                static::query()
                    ->where('user_id', $profile->user_id)
                    ->whereKeyNot($profile->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            app(TvClientConfigService::class)->notifyProfileChanged($profile);
        });

        static::deleting(function (TvClientProfile $profile): void {
            app(TvClientConfigService::class)->notifyProfileChanged($profile);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlistAuths(): HasMany
    {
        return $this->hasMany(PlaylistAuth::class);
    }

    /**
     * @return array<string, array{mode: string, value: string|int|bool}>
     */
    public function managedSettings(): array
    {
        return TvClientSettings::normalize($this->settings);
    }
}
