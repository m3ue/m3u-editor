<?php

use App\Filament\Resources\Epgs\EpgResource;
use App\Models\Playlist;
use App\Models\User;

function mismatchPlaylist(?array $xtreamConfig): Playlist
{
    return Playlist::withoutEvents(fn () => Playlist::factory()->for(User::factory())->create([
        'xtream' => $xtreamConfig !== null,
        'xtream_config' => $xtreamConfig,
        'xtream_fallback_urls' => $xtreamConfig !== null ? ['http://fallback.example.com:8080'] : null,
    ]));
}

it('returns false when no playlist or no url is given', function () {
    expect(EpgResource::providerHostsMismatch(null, null))->toBeFalse()
        ->and(EpgResource::providerHostsMismatch('http://a.example.com/epg.xml', null))->toBeFalse()
        ->and(EpgResource::providerHostsMismatch(null, 1))->toBeFalse();
});

it('returns false when the EPG host matches a provider URL host', function () {
    $playlist = mismatchPlaylist([
        'url' => 'http://primary.example.com:8080',
        'username' => 'user',
        'password' => 'pass',
    ]);

    expect(EpgResource::providerHostsMismatch(
        'http://fallback.example.com:8080/xmltv.php?username=user&password=pass',
        $playlist->id,
    ))->toBeFalse();
});

it('returns true when the EPG host is unrelated to the provider', function () {
    $playlist = mismatchPlaylist([
        'url' => 'http://primary.example.com:8080',
        'username' => 'user',
        'password' => 'pass',
    ]);

    expect(EpgResource::providerHostsMismatch(
        'http://some-other-guide.example.net/epg.xml',
        $playlist->id,
    ))->toBeTrue();
});

it('treats a non-xtream playlist as a mismatch', function () {
    $playlist = mismatchPlaylist(null);

    expect(EpgResource::providerHostsMismatch('http://anything.example.com/epg.xml', $playlist->id))->toBeTrue();
});
