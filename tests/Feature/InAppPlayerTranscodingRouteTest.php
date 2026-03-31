<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\StreamProfile;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;

function createTestStreamProfile(User $user, string $format): StreamProfile
{
    return StreamProfile::query()->create([
        'user_id' => $user->id,
        'name' => 'Test '.$format.' profile',
        'description' => 'Test stream profile',
        'args' => '{}',
        'format' => $format,
    ]);
}

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Config::set('cache.default', 'array');
    Config::set('session.driver', 'array');

    $this->user = User::factory()->create(['name' => 'testuser']);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('uses a signed in-app player route for floating live channel playback', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/123.ts',
        'is_vod' => false,
    ]);

    $attributes = $channel->getFloatingPlayerAttributes();

    expect($attributes['url'])->toContain('/api/m3u-proxy/channel/'.$channel->id.'/player');
    expect($attributes['url'])->toContain('signature=');
    expect($attributes['url'])->not->toContain('?proxy=true');
});

it('uses live in-app transcoding format only for floating live channel playback', function () {
    $profile = createTestStreamProfile($this->user, 'm3u8');

    $settings = new GeneralSettings;
    $settings->default_stream_profile_id = $profile->id;
    $settings->default_vod_stream_profile_id = null;
    app()->instance(GeneralSettings::class, $settings);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/123.ts',
        'is_vod' => false,
    ]);

    $attributes = $channel->getFloatingPlayerAttributes();
    [$proxyUrl, $proxyFormat] = $channel->getProxyUrl(withFormat: true);

    expect($attributes['format'])->toBe('m3u8');
    expect($proxyFormat)->toBe('ts');
    expect($proxyUrl)->toContain('.ts?proxy=true');
});

it('uses a signed in-app player route for floating vod playback', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/movie/123.mkv',
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);

    $attributes = $channel->getFloatingPlayerAttributes();

    expect($attributes['url'])->toContain('/api/m3u-proxy/channel/'.$channel->id.'/player');
    expect($attributes['url'])->toContain('signature=');
    expect($attributes['url'])->not->toContain('?proxy=true');
});

it('uses vod in-app transcoding format only for floating vod playback', function () {
    $profile = createTestStreamProfile($this->user, 'm3u8');

    $settings = new GeneralSettings;
    $settings->default_stream_profile_id = null;
    $settings->default_vod_stream_profile_id = $profile->id;
    app()->instance(GeneralSettings::class, $settings);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/movie/123.mkv',
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);

    $attributes = $channel->getFloatingPlayerAttributes();
    [$proxyUrl, $proxyFormat] = $channel->getProxyUrl(withFormat: true);

    expect($attributes['format'])->toBe('m3u8');
    expect($proxyFormat)->toBe('mkv');
    expect($proxyUrl)->toContain('.mkv?proxy=true');
});

it('uses a signed in-app player route for floating episode playback', function () {
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

    $attributes = $episode->getFloatingPlayerAttributes();

    expect($attributes['url'])->toContain('/api/m3u-proxy/episode/'.$episode->id.'/player');
    expect($attributes['url'])->toContain('signature=');
    expect($attributes['url'])->not->toContain('?proxy=true');
});

it('uses vod in-app transcoding format only for floating episode playback', function () {
    $profile = createTestStreamProfile($this->user, 'm3u8');

    $settings = new GeneralSettings;
    $settings->default_stream_profile_id = null;
    $settings->default_vod_stream_profile_id = $profile->id;
    app()->instance(GeneralSettings::class, $settings);

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

    $attributes = $episode->getFloatingPlayerAttributes();
    [$proxyUrl, $proxyFormat] = $episode->getProxyUrl(withFormat: true);

    expect($attributes['format'])->toBe('m3u8');
    expect($proxyFormat)->toBe('mkv');
    expect($proxyUrl)->toContain('.mkv?proxy=true');
});
