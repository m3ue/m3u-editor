<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'testuser']);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('returns a live channel proxy URL in Xtream format', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/123',
    ]);

    $url = $channel->getProxyUrl();

    expect($url)
        ->toContain('/live/testuser/'.$this->playlist->uuid.'/'.$channel->id)
        ->toContain('?proxy=true');
});

it('returns a vod channel proxy URL using movie path', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/movie/123.mkv',
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);

    $url = $channel->getProxyUrl();

    expect($url)
        ->toContain('/movie/testuser/')
        ->toContain('?proxy=true');
});

it('detects m3u8 format from URL extension', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream.m3u8',
    ]);

    [$url, $format] = $channel->getProxyUrl(withFormat: true);

    expect($format)->toBe('m3u8');
    expect($url)->toContain('.m3u8');
});

it('detects ts format from URL extension', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream.ts',
    ]);

    [$url, $format] = $channel->getProxyUrl(withFormat: true);

    expect($format)->toBe('ts');
    expect($url)->toContain('.ts');
});

it('overrides format when profileFormat is provided', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream.ts',
    ]);

    [$url, $format] = $channel->getProxyUrl(withFormat: true, profileFormat: 'mkv');

    expect($format)->toBe('mkv');
    expect($url)->toContain('.mkv');
});

it('returns both url and format when withFormat is true', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/123',
    ]);

    $result = $channel->getProxyUrl(withFormat: true);

    expect($result)->toBeArray()->toHaveCount(2);
    expect($result[0])->toBeString()->toContain('?proxy=true');
    expect($result[1])->toBeString();
});

it('returns an episode proxy URL in Xtream series format', function () {
    $series = Series::factory()->for($this->user)->for($this->playlist)->create();
    $season = Season::factory()->for($series)->create();
    $episode = Episode::factory()
        ->for($this->user)
        ->for($this->playlist)
        ->for($series)
        ->for($season)
        ->create([
            'url' => 'http://provider.test/episode/123.mkv',
            'container_extension' => 'mkv',
        ]);

    $url = $episode->getProxyUrl();

    expect($url)
        ->toContain('/series/testuser/'.$this->playlist->uuid.'/'.$episode->id)
        ->toContain('?proxy=true');
});

it('episode proxy URL includes correct format extension', function () {
    $series = Series::factory()->for($this->user)->for($this->playlist)->create();
    $season = Season::factory()->for($series)->create();
    $episode = Episode::factory()
        ->for($this->user)
        ->for($this->playlist)
        ->for($series)
        ->for($season)
        ->create([
            'url' => 'http://provider.test/episode/123',
            'container_extension' => 'mkv',
        ]);

    [$url, $format] = $episode->getProxyUrl(withFormat: true);

    expect($format)->toBe('mkv');
    expect($url)->toContain('.mkv');
});
