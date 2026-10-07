<?php

namespace App\Filament\GuestPanel\Pages\Concerns;

use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\Episode;

trait HasPlaylist
{
    protected static function getCurrentUuid(): ?string
    {
        $referer = request()->header('referer');
        $refererSegment2 = $referer ? (explode('/', parse_url($referer, PHP_URL_PATH))[3] ?? null) : null;
        $uuid = request()->route('uuid') ?? request()->attributes->get('playlist_uuid') ?? $refererSegment2;

        return $uuid;
    }

    protected static function getCurrentAuth(): ?array
    {
        // Get the username and password from the session if available
        $uuid = self::getCurrentUuid();
        $prefix = $uuid ? base64_encode($uuid).'_' : '';
        $username = session("{$prefix}guest_auth_username", '');
        $password = session("{$prefix}guest_auth_password", '');

        if ($username && $password) {
            return [
                'username' => $username,
                'password' => $password,
            ];
        }

        return null;
    }

    /**
     * The floating player payload for a record, built with the session's own
     * credentials so the stream URL never carries the playlist owner's login.
     */
    public static function playerAttributes(Channel|Episode|DvrRecording $record): array
    {
        $credentials = static::getCurrentAuth();

        return $record->getFloatingPlayerAttributes(
            username: $credentials['username'] ?? null,
            password: $credentials['password'] ?? null,
        );
    }
}
