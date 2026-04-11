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

it('falls back to series tmdb_id when episode has no tmdb_id', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'include_series_in_m3u' => true,
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
        'tmdb_id' => 87739,
    ]);

    $season = Season::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'category_id' => $category->id,
    ]);

    Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'season_id' => $season->id,
        'title' => 'Pilot',
        'tmdb_id' => null,
        'enabled' => true,
    ]);

    $response = $this->get(route('playlist.generate', ['uuid' => $playlist->uuid]));

    $response->assertSuccessful();
    $content = $response->streamedContent();

    expect($content)->toContain('tmdb-id="87739"');
});

it('falls back to series metadata tmdb when episode and series tmdb_id are null', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'include_series_in_m3u' => true,
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
        'tmdb_id' => null,
        'metadata' => ['tmdb' => '12345'],
    ]);

    $season = Season::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'category_id' => $category->id,
    ]);

    Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'season_id' => $season->id,
        'title' => 'Pilot',
        'tmdb_id' => null,
        'enabled' => true,
    ]);

    $response = $this->get(route('playlist.generate', ['uuid' => $playlist->uuid]));

    $response->assertSuccessful();
    $content = $response->streamedContent();

    expect($content)->toContain('tmdb-id="12345"');
});

it('prefers episode tmdb_id over series tmdb_id', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'include_series_in_m3u' => true,
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
        'tmdb_id' => 87739,
    ]);

    $season = Season::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'category_id' => $category->id,
    ]);

    Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'season_id' => $season->id,
        'title' => 'Pilot',
        'tmdb_id' => 99999,
        'enabled' => true,
    ]);

    $response = $this->get(route('playlist.generate', ['uuid' => $playlist->uuid]));

    $response->assertSuccessful();
    $content = $response->streamedContent();

    expect($content)->toContain('tmdb-id="99999"');
});
