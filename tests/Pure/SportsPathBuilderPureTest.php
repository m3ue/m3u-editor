<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Series;
use App\Services\SportsPathBuilder;

test('extracts season year from title and builds sequential episode code', function () {
    $builder = new SportsPathBuilder;
    $channel = new Channel;
    $channel->title = 'Formel 1 GP Japan - Qualifying 2026';
    $channel->group = 'Formula 1';

    $year = $builder->resolveSeasonYearForChannel($channel, ['sports_season_source' => 'title_year']);
    $code = $builder->buildEpisodeCode($year, 1, 'sequential_per_season');

    expect($year)->toBe(2026)
        ->and($code)->toBe('S2026E01');
});

test('falls back to current year when no season year can be parsed', function () {
    $builder = new SportsPathBuilder;
    $channel = new Channel;
    $channel->title = 'MotoGPItaly';
    $channel->group = 'MotoGP';

    $year = $builder->resolveSeasonYearForChannel($channel, ['sports_season_source' => 'title_year']);

    expect($year)->toBe((int) date('Y'));
});

test('resolves league and year for series episodes and date-code strategy', function () {
    $builder = new SportsPathBuilder;

    $series = new Series;
    $series->name = 'American NFL';
    $series->release_date = '2015-09-01';

    $episode = new Episode;
    $episode->title = 'American NFL Season Opener';
    $episode->episode_num = 1;
    $episode->info = ['air_date' => '2015-09-10'];

    $league = $builder->resolveLeagueForSeries($series, ['sports_league_source' => 'series_name']);
    $year = $builder->resolveSeasonYearForEpisode($episode, $series, ['sports_season_source' => 'release_date']);
    $dateCode = $builder->buildEpisodeCode($year, 1, 'date_code', $builder->resolveEventDateForEpisode($episode));

    expect($league)->toBe('American NFL')
        ->and($year)->toBe(2015)
        ->and($dateCode)->toBe('S2015E2015091001');
});
