<?php

use App\Models\Episode;
use App\Models\Season;
use App\Models\Series;
use App\Models\StreamFileSetting;
use App\Services\SerieFileNameService;

it('generates a trash guide conform episode filename', function () {
    $series = new Series(['name' => 'Breaking Bad']);
    $season = new Season(['season_number' => 1]);
    $season->setRelation('series', $series);
    $episode = new Episode([
        'title' => 'Pilot',
        'episode_num' => 1,
        'season_number' => 1,
        'stream_stats' => [
            'resolution' => '1920x1080',
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'audio_channels' => 2,
        ],
    ]);

    $episode->setRelation('season', $season);
    $episode->setRelation('series', $series);

    $setting = new StreamFileSetting([
        'episode_format' => '{title} - S{season}E{episode}{-title} [{quality} {video} {audio}]',
        'use_stream_stats' => true,
    ]);

    $fileName = (new SerieFileNameService)->generateEpisodeFileName($episode, $setting);

    expect($fileName)->toBe('Breaking Bad - S01E01 - Pilot [1080p H.264 AAC 2.0]');
});

it('generates episode filename without optional parts when empty', function () {
    $series = new Series(['name' => 'Test Show']);
    $season = new Season(['season_number' => 2]);
    $season->setRelation('series', $series);
    $episode = new Episode([
        'title' => '',
        'episode_num' => 5,
        'season_number' => 2,
    ]);
    $episode->setRelation('season', $season);
    $episode->setRelation('series', $series);

    $setting = new StreamFileSetting([
        'episode_format' => '{title} - S{season}E{episode}{-title} [{quality}]',
        'use_stream_stats' => false,
    ]);

    $fileName = (new SerieFileNameService)->generateEpisodeFileName($episode, $setting);

    expect($fileName)->toBe('Test Show - S02E05');
});

it('generates season folder name', function () {
    $season = new Season(['season_number' => 3]);

    $folderName = (new SerieFileNameService)->generateSeasonFolderName($season);

    expect($folderName)->toBe('Season 03');
});

it('generates series folder name', function () {
    $series = new Series(['name' => 'Test: Series / Name']);

    $folderName = (new SerieFileNameService)->generateSerieFolderName($series);

    expect($folderName)->toBe('Test Series Name');
});

it('detects quality through filename generation', function () {
    $series = new Series(['name' => 'Show']);
    $season = new Season(['season_number' => 1]);
    $season->setRelation('series', $series);
    $episode = new Episode([
        'title' => 'Ep',
        'episode_num' => 1,
        'season_number' => 1,
        'stream_stats' => ['resolution' => '1280x720'],
    ]);
    $episode->setRelation('season', $season);
    $episode->setRelation('series', $series);

    $setting = new StreamFileSetting([
        'episode_format' => '{title} [{quality}]',
        'use_stream_stats' => true,
    ]);

    expect((new SerieFileNameService)->generateEpisodeFileName($episode, $setting))
        ->toBe('Show [720p]');
});

it('detects audio through filename generation', function () {
    $series = new Series(['name' => 'Show']);
    $season = new Season(['season_number' => 1]);
    $season->setRelation('series', $series);
    $episode = new Episode([
        'title' => 'Ep',
        'episode_num' => 1,
        'season_number' => 1,
        'stream_stats' => [
            'audio_codec' => 'eac3',
            'audio_channels' => 6,
        ],
    ]);
    $episode->setRelation('season', $season);
    $episode->setRelation('series', $series);

    $setting = new StreamFileSetting([
        'episode_format' => '{title} [{audio}]',
        'use_stream_stats' => true,
    ]);

    expect((new SerieFileNameService)->generateEpisodeFileName($episode, $setting))
        ->toBe('Show [E-AC-3 5.1]');
});

it('detects hdr through filename generation', function () {
    $series = new Series(['name' => 'Show']);
    $season = new Season(['season_number' => 1]);
    $season->setRelation('series', $series);
    $episode = new Episode([
        'title' => 'Ep',
        'episode_num' => 1,
        'season_number' => 1,
        'stream_stats' => ['hdr' => 'HDR10'],
    ]);
    $episode->setRelation('season', $season);
    $episode->setRelation('series', $series);

    $setting = new StreamFileSetting([
        'episode_format' => '{title} [{hdr}]',
        'use_stream_stats' => true,
    ]);

    expect((new SerieFileNameService)->generateEpisodeFileName($episode, $setting))
        ->toBe('Show [HDR]');
});

it('generates full path with all components', function () {
    $series = new Series(['name' => 'The Office']);
    $season = new Season(['season_number' => 1]);
    $season->setRelation('series', $series);
    $episode = new Episode([
        'title' => 'Diversity Day',
        'episode_num' => 2,
        'season_number' => 1,
    ]);
    $episode->setRelation('season', $season);
    $episode->setRelation('series', $series);

    $setting = new StreamFileSetting([
        'episode_format' => '{title} - S{season}E{episode} - {ep_title}',
    ]);

    $path = (new SerieFileNameService)->generateFullPath($episode, $setting);

    expect($path)->toBe('The Office/Season 01/The Office - S01E02 - Diversity Day.strm');
});
