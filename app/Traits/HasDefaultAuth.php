<?php

namespace App\Traits;

use App\Enums\DefaultAuthMode;
use App\Services\PlaylistCredentialResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The playlist owner's "default" login: username = the owner's name, and the
 * password is the playlist UUID, a custom password, or nothing at all (Playlist
 * Auths only), depending on default_auth_mode.
 */
trait HasDefaultAuth
{
    public static function bootHasDefaultAuth(): void
    {
        // Changing the default login rotates the internal token, so links issued
        // with the old one (copied proxy URLs, STRM files) stop working. Registered on
        // "saving" rather than "updating": AppServiceProvider's Playlist::updating
        // listener returns the model, which halts the updating chain before trait
        // listeners run.
        static::saving(function (Model $playlist): void {
            if ($playlist->exists && $playlist->isDirty(['default_auth_mode', 'default_auth_password'])) {
                $playlist->internal_auth_secret = Str::random(40);
            }
        });

        // Two of an owner's playlists can't share a custom password, so a copy never
        // takes it: a Custom Password copy starts with the default login disabled
        // rather than silently falling back to UUID login.
        static::replicating(function (Model $copy): void {
            if ($copy->getDefaultAuthMode() === DefaultAuthMode::Custom) {
                $copy->default_auth_mode = DefaultAuthMode::Disabled;
            }
            $copy->default_auth_password = null;
            $copy->internal_auth_secret = null;
        });
    }

    public function initializeHasDefaultAuth(): void
    {
        $this->mergeCasts(['default_auth_mode' => DefaultAuthMode::class]);
        $this->makeHidden('internal_auth_secret');
    }

    public function getDefaultAuthMode(): DefaultAuthMode
    {
        return $this->default_auth_mode ?? DefaultAuthMode::Uuid;
    }

    /**
     * The password clients use for the default login, or null when the default
     * login is disabled (or Custom Password is selected with no password set).
     */
    public function getDefaultAuthPassword(): ?string
    {
        return match ($this->getDefaultAuthMode()) {
            DefaultAuthMode::Uuid => $this->uuid,
            DefaultAuthMode::Custom => filled($this->default_auth_password) ? $this->default_auth_password : null,
            DefaultAuthMode::Disabled => null,
        };
    }

    /**
     * The owner's name and default password, or null when the default login is unavailable.
     *
     * @return object{username: string, password: string}|null
     */
    public function getDefaultAuthCredentials(): ?object
    {
        $password = $this->getDefaultAuthPassword();
        if ($password === null || ! $this->user) {
            return null;
        }

        return (object) [
            'username' => $this->user->name,
            'password' => $password,
        ];
    }

    /**
     * Password for URLs the app builds for itself (in-app player, EPG viewer,
     * proxy, STRM files). While UUID login is on this is the UUID, so those URLs
     * are unchanged. Otherwise it is the internal token, which only the owner's
     * login accepts and which rotates whenever the default login changes.
     */
    public function getInternalAuthPassword(): string
    {
        return $this->getDefaultAuthMode() === DefaultAuthMode::Uuid
            ? $this->uuid
            : $this->getInternalAuthToken();
    }

    /**
     * The current internal token, accepted with the owner's name in every mode.
     */
    public function getInternalAuthToken(): string
    {
        return PlaylistCredentialResolver::internalToken($this->uuid, $this->internal_auth_secret);
    }
}
