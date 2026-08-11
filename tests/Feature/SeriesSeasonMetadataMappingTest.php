<?php

use App\Models\Category;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://provider.test',
            'username' => 'test-user',
            'password' => 'test-password',
        ],
    ]));
    $this->category = Category::factory()->for($this->user)->for($this->playlist)->create();
    $this->series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $this->category->id,
        'source_series_id' => 123,
        'enabled' => true,
        'last_metadata_fetch' => null,
        'last_modified' => null,
    ]);
});

it('maps zero-indexed provider season metadata to the real season number', function () {
    Http::fake([
        'provider.test/player_api.php*' => Http::response([
            'info' => ['name' => 'Test Series'],
            'seasons' => [
                ['id' => 101, 'season_number' => 1, 'name' => 'Season One', 'episode_count' => 1],
                ['id' => 102, 'season_number' => 2, 'name' => 'Season Two', 'episode_count' => 1],
            ],
            'episodes' => [
                '1' => [[
                    'id' => 1001,
                    'episode_num' => 1,
                    'title' => 'S01E01 - First Episode',
                    'container_extension' => 'mkv',
                    'info' => [],
                ]],
                '2' => [[
                    'id' => 2001,
                    'episode_num' => 1,
                    'title' => 'S02E01 - Second Episode',
                    'container_extension' => 'mkv',
                    'info' => [],
                ]],
            ],
        ]),
    ]);

    expect($this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false))->toBeTrue();

    $seasonOne = Season::where('series_id', $this->series->id)->where('season_number', 1)->firstOrFail();
    $seasonTwo = Season::where('series_id', $this->series->id)->where('season_number', 2)->firstOrFail();

    expect($seasonOne->name)->toBe('Season One')
        ->and($seasonOne->source_season_id)->toBe(101)
        ->and($seasonTwo->name)->toBe('Season Two')
        ->and($seasonTwo->source_season_id)->toBe(102);
});

it('reassigns an existing episode when the provider moves it to another season', function () {
    Http::fakeSequence('provider.test/player_api.php*')
        ->push([
            'info' => ['name' => 'Test Series'],
            'seasons' => [
                ['id' => 101, 'season_number' => 1, 'name' => 'Season One'],
            ],
            'episodes' => [
                '1' => [[
                    'id' => 1001,
                    'episode_num' => 1,
                    'title' => 'S01E01 - Episode',
                    'container_extension' => 'mkv',
                    'info' => [],
                ]],
            ],
        ])
        ->push([
            'info' => ['name' => 'Test Series'],
            'seasons' => [
                ['id' => 101, 'season_number' => 1, 'name' => 'Season One'],
                ['id' => 102, 'season_number' => 2, 'name' => 'Season Two'],
            ],
            'episodes' => [
                '2' => [[
                    'id' => 1001,
                    'episode_num' => 1,
                    'title' => 'S02E01 - Episode',
                    'container_extension' => 'mkv',
                    'info' => [],
                ]],
            ],
        ]);

    expect($this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false))->toBeTrue();
    $episode = Episode::where('source_episode_id', 1001)->firstOrFail();
    $seasonOne = Season::where('series_id', $this->series->id)->where('season_number', 1)->firstOrFail();
    expect($episode->season_id)->toBe($seasonOne->id);

    expect($this->series->fresh()->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false))->toBeTrue();
    $episode->refresh();
    $seasonTwo = Season::where('series_id', $this->series->id)->where('season_number', 2)->firstOrFail();

    expect($episode->season)->toBe(2)
        ->and($episode->season_id)->toBe($seasonTwo->id);
});
