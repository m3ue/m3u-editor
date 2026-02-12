<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // 'avatar_url' => 'array'
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'permissions' => 'array',
        ];
    }

    public function getAppAuthenticationSecret(): ?string
    {
        // This method should return the user's saved app authentication secret.

        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        // This method should save the user's app authentication secret.

        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        // In a user's authentication app, each account can be represented by a "holder name".
        // If the user has multiple accounts in your app, it might be a good idea to use
        // their email address as then they are still uniquely identifiable.

        return $this->name;
    }

    /**
     * @return ?array<string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        // This method should return the user's saved app authentication recovery codes.

        return $this->app_authentication_recovery_codes;
    }

    /**
     * @param  array<string> | null  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        // This method should save the user's app authentication recovery codes.

        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    /**
     * Who can access the Filament panel in production?
     * Allow all users by default.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_url;
    }

    /**
     * Users playlists.
     */
    public function playlists()
    {
        return $this->hasMany(Playlist::class);
    }

    /**
     * Users custom playlists.
     */
    public function customPlaylists()
    {
        return $this->hasMany(CustomPlaylist::class);
    }

    /**
     * Users merged playlists.
     */
    public function mergedPlaylists()
    {
        return $this->hasMany(MergedPlaylist::class);
    }

    /**
     * Users playlist aliases.
     */
    public function playlistAliases()
    {
        return $this->hasMany(PlaylistAlias::class);
    }

    /**
     * Get all playlist UUIDs for this user.
     *
     * @return array<string>
     */
    public function getAllPlaylistUuids(): array
    {
        $uuids = $this->playlists()->select('id', 'user_id', 'uuid')->pluck('uuid')->toArray();
        $uuids = array_merge($uuids, $this->customPlaylists()->select('id', 'user_id', 'uuid')->pluck('uuid')->toArray());
        $uuids = array_merge($uuids, $this->mergedPlaylists()->select('id', 'user_id', 'uuid')->pluck('uuid')->toArray());
        $uuids = array_merge($uuids, $this->playlistAliases()->select('id', 'user_id', 'uuid')->pluck('uuid')->toArray());

        return $uuids;
    }

    /**
     * Users epgs.
     */
    public function epgs()
    {
        return $this->hasMany(Epg::class);
    }

    public function channels()
    {
        return $this->hasMany(Channel::class);
    }

    public function epgChannels()
    {
        return $this->hasMany(EpgChannel::class);
    }

    public function series()
    {
        return $this->hasMany(Series::class);
    }

    /**
     * Check if user is an admin.
     * Admin users have full access to all resources in the system.
     */
    public function isAdmin(): bool
    {
        return in_array($this->email, config('dev.admin_emails', []));
    }

    /**
     * Check if user has a specific permission.
     * Admins always have all permissions.
     */
    public function hasPermission(string $permission): bool
    {
        // Admins have all permissions
        if ($this->isAdmin()) {
            return true;
        }

        $permissions = $this->permissions ?? [];

        return in_array($permission, $permissions);
    }

    /**
     * Check if user can use the proxy feature.
     */
    public function canUseProxy(): bool
    {
        return $this->hasPermission('use_proxy');
    }

    /**
     * Check if user can use integrations.
     */
    public function canUseIntegrations(): bool
    {
        return $this->hasPermission('use_integrations');
    }

    /**
     * Check if user can use tools.
     */
    public function canUseTools(): bool
    {
        return $this->hasPermission('use_tools');
    }

    /**
     * Get all available permissions.
     */
    public static function getAvailablePermissions(): array
    {
        return [
            'use_proxy' => 'Use Proxy',
            'use_integrations' => 'Use Integrations',
            'use_tools' => 'Use Tools',
        ];
    }
}
