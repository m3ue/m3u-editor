<?php

use App\Models\Episode;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('series season and episode relations are wired correctly', function () {
    Event::fake();

    $user = User::factory()->create();
    $series = Series::factory()->create(['user_id' => $user->id]);
    $season = Season::factory()->create([
        'user_id' => $user->id,
        'series_id' => $series->id,
        'season_number' => 1,
    ]);
    $episode = Episode::factory()->create([
        'user_id' => $user->id,
        'series_id' => $series->id,
        'season_id' => $season->id,
        'season' => 1,
        'episode_num' => 1,
    ]);

    expect($series->seasons)->toHaveCount(1)
        ->and($series->episodes)->toHaveCount(1)
        ->and($season->serie->is($series))->toBeTrue()
        ->and($season->episodes->first()?->is($episode))->toBeTrue()
        ->and($episode->season()->getResults()?->is($season))->toBeTrue()
        ->and($episode->serie()->getResults()?->is($series))->toBeTrue()
        ->and($episode->episode_number)->toBe(1)
        ->and($episode->formatted_episode_number)->toBe('S01E01');
});

test('episode and season scopes filter by parent ids', function () {
    Event::fake();

    $user = User::factory()->create();
    $series = Series::factory()->create(['user_id' => $user->id]);
    $otherSeries = Series::factory()->create(['user_id' => $user->id]);

    $season = Season::factory()->create([
        'user_id' => $user->id,
        'series_id' => $series->id,
        'season_number' => 1,
    ]);
    $otherSeason = Season::factory()->create([
        'user_id' => $user->id,
        'series_id' => $otherSeries->id,
        'season_number' => 2,
    ]);

    Episode::factory()->create([
        'user_id' => $user->id,
        'series_id' => $series->id,
        'season_id' => $season->id,
        'season' => 1,
        'episode_num' => 1,
    ]);
    Episode::factory()->create([
        'user_id' => $user->id,
        'series_id' => $otherSeries->id,
        'season_id' => $otherSeason->id,
        'season' => 2,
        'episode_num' => 1,
    ]);

    $seriesSeasonIds = Season::forSerie($series->id)->pluck('id')->all();
    $seasonEpisodeIds = Episode::forSeason($season->id)->pluck('id')->all();

    expect($seriesSeasonIds)->toContain($season->id)
        ->and($seriesSeasonIds)->not->toContain($otherSeason->id)
        ->and($seasonEpisodeIds)->toHaveCount(1);
});
