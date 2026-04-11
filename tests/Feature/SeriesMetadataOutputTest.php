<?php

use App\Models\Category;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    $this->user = User::factory()->create();
});

it('includes season and episode metadata in M3U output when enabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'include_series_in_m3u' => true,
        'include_series_metadata_in_m3u' => true,
        'xtream' => true,
    ]);

    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
    ]);

    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Test Show',
    ]);

    $season = Season::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'category_id' => $category->id,
        'season_number' => 2,
    ]);

    Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'season_id' => $season->id,
        'season' => 2,
        'episode_num' => 5,
        'title' => 'The Great Episode',
        'enabled' => true,
    ]);

    $response = $this->get(route('playlist.generate', ['uuid' => $playlist->uuid]));

    $response->assertSuccessful();
    $content = $response->streamedContent();

    expect($content)->toContain('tvg-season="2"');
    expect($content)->toContain('tvg-episode="5"');
    expect($content)->toContain('The Great Episode');
});

it('omits season and episode metadata when setting is disabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'include_series_in_m3u' => true,
        'include_series_metadata_in_m3u' => false,
        'xtream' => true,
    ]);

    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
    ]);

    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Another Show',
    ]);

    $season = Season::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'category_id' => $category->id,
        'season_number' => 1,
    ]);

    Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'season_id' => $season->id,
        'season' => 1,
        'episode_num' => 3,
        'title' => 'Hidden Metadata',
        'enabled' => true,
    ]);

    $response = $this->get(route('playlist.generate', ['uuid' => $playlist->uuid]));

    $response->assertSuccessful();
    $content = $response->streamedContent();

    expect($content)->not->toContain('tvg-season=');
    expect($content)->not->toContain('tvg-episode=');
    expect($content)->toContain('Hidden Metadata');
});
