<?php

namespace App\Traits;

use App\Enums\DefaultAuthMode;
use App\Services\PlaylistCredentialResolver;

/**
 * The playlist owner's "default" login: username = the owner's name, and the
 * password is the playlist UUID, a custom password, or nothing at all (Playlist
 * Auths only), depending on default_auth_mode.
 */
trait HasDefaultAuth
{
    public function initializeHasDefaultAuth(): void
    {
        $this->mergeCasts(['default_auth_mode' => DefaultAuthMode::class]);
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
     * Password for URLs the app builds for itself (in-app player, DVR, EPG viewer,
     * proxy). While UUID login is on this is the UUID, so those URLs are unchanged.
     * Otherwise it is a private token that only the owner's login accepts, so
     * disabling the default login or changing the custom password doesn't break them.
     */
    public function getInternalAuthPassword(): string
    {
        return $this->getDefaultAuthMode() === DefaultAuthMode::Uuid
            ? $this->uuid
            : PlaylistCredentialResolver::internalToken($this->uuid);
    }
}
