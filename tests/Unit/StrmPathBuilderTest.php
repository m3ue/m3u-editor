<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Season;
use App\Models\Series;
use App\Models\StreamFileSetting;
use App\Services\SerieFileNameService;
use App\Services\StrmPathBuilder;
use App\Services\VodFileNameService;

it('builds vod path with default structure', function () {
    $channel = new Channel([
        'name' => 'Test Movie',
        'year' => 2024,
        'group' => 'Action',
    ]);

    $setting = new StreamFileSetting([
        'movie_format' => '{title} ({year})',
        'use_stream_stats' => false,
    ]);

    $syncSettings = [
        'sync_location' => '/tmp/sync',
        'path_structure' => ['group', 'title'],
    ];

    $path = (new StrmPathBuilder(new VodFileNameService, new SerieFileNameService))
        ->buildVodPath($channel, $setting, $syncSettings);

    expect($path)->toBe('/tmp/sync/Action/Test Movie (2024)/Test Movie (2024).strm');
});

it('builds episode path with all components', function () {
    $series = new Series(['name' => 'Breaking Bad', 'release_date' => '2008-01-20']);
    $season = new Season(['season_number' => 1]);
    $season->setRelation('series', $series);
    $episode = new Episode([
        'title' => 'Pilot',
        'episode_num' => 1,
        'season_number' => 1,
    ]);
    $episode->setRelation('season', $season);
    $episode->setRelation('series', $series);

    $setting = new StreamFileSetting([
        'episode_format' => '{title} - S{season}E{episode} - {ep_title}',
    ]);

    $syncSettings = [
        'sync_location' => '/tmp/series',
        'path_structure' => ['series', 'season'],
    ];

    $path = (new StrmPathBuilder(new VodFileNameService, new SerieFileNameService))
        ->buildEpisodePath($episode, $setting, $syncSettings);

    expect($path)->toBe('/tmp/series/Breaking Bad (2008)/Season 01/Breaking Bad - S01E01 - Pilot.strm');
});

it('builds episode path without season folder', function () {
    $series = new Series(['name' => 'Show Name']);
    $season = new Season(['season_number' => 2]);
    $season->setRelation('series', $series);
    $episode = new Episode([
        'title' => 'Episode Title',
        'episode_num' => 5,
        'season_number' => 2,
    ]);
    $episode->setRelation('season', $season);
    $episode->setRelation('series', $series);

    $setting = new StreamFileSetting([
        'episode_format' => '{title} - S{season}E{episode}',
    ]);

    $syncSettings = [
        'sync_location' => '/tmp/sync',
        'path_structure' => ['series'],
    ];

    $path = (new StrmPathBuilder(new VodFileNameService, new SerieFileNameService))
        ->buildEpisodePath($episode, $setting, $syncSettings);

    expect($path)->toBe('/tmp/sync/Show Name/Show Name - S02E05.strm');
});

it('builds vod path without group folder', function () {
    $channel = new Channel([
        'name' => 'Simple Movie',
        'year' => 2023,
    ]);

    $setting = new StreamFileSetting([
        'movie_format' => '{title}',
        'use_stream_stats' => false,
    ]);

    $syncSettings = [
        'sync_location' => '/tmp/movies',
        'path_structure' => ['title'],
    ];

    $path = (new StrmPathBuilder(new VodFileNameService, new SerieFileNameService))
        ->buildVodPath($channel, $setting, $syncSettings);

    expect($path)->toBe('/tmp/movies/Simple Movie/Simple Movie.strm');
});
