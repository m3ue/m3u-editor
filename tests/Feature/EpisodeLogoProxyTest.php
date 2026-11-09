<?php

use App\Models\Episode;
use App\Models\Playlist;
use App\Models\User;
use App\Services\LogoService;

it('proxies remote episode thumbnails based on Enable Logo Proxy, not Enable Stream Proxy', function () {
    $user = User::factory()->create();

    $playlist = Playlist::factory()->for($user)->create([
        'enable_logo_proxy' => true,
        'enable_proxy' => false,
    ]);
    $episode = Episode::factory()->for($user)->for($playlist)->create([
        'info' => ['movie_image' => 'https://example.com/still.jpg'],
    ]);

    expect(LogoService::getEpisodeLogoUrl($episode))->toContain('/logo-proxy');

    $playlist->update(['enable_logo_proxy' => false, 'enable_proxy' => true]);
    $episode->refresh();

    expect(LogoService::getEpisodeLogoUrl($episode))->toBe('https://example.com/still.jpg');
});
