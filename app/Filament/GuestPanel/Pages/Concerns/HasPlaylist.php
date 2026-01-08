<?php

namespace App\Filament\GuestPanel\Pages\Concerns;

trait HasPlaylist
{
    protected static function getCurrentUuid(): ?string
    {
        $referer = request()->header('referer');
        $refererSegment2 = $referer ? (explode('/', parse_url($referer, PHP_URL_PATH))[3] ?? null) : null;
        $uuid = request()->route('uuid') ?? request()->attributes->get('playlist_uuid') ?? $refererSegment2;

        return $uuid;
    }
}
