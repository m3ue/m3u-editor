<?php

use App\Models\Episode;
use App\Models\Season;
use App\Models\Series;
use App\Models\StreamFileSetting;
use App\Services\SerieFileNameService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->service = new SerieFileNameService;
});

it('generates trash guide episode filenames from the configured format', function () {
    $serie = Series::factory()->create(['name' => 'Show: Name / Special']);
    $season = Season::factory()->for($serie, 'series')->create(['season_number' => 1]);
    $episode = Episode::factory()
        ->for($serie, 'series')
        ->for($season)
        ->create([
            'title' => 'Pilot: Part / One',
            'season' => 1,
            'episode_num' => 1,
            'stream_stats' => [
                ['stream' => ['codec_type' => 'video', 'codec_name' => 'hevc', 'height' => 1080]],
                ['stream' => ['codec_type' => 'audio', 'codec_name' => 'eac3', 'channels' => 6]],
            ],
        ]);
    $setting = new StreamFileSetting([
        'episode_format' => '{title} - S{season}E{episode} - {ep_title} {quality} {audio} {video}',
    ]);

    $fileName = $this->service->generateEpisodeFileName($episode, $setting);

    expect($fileName)->toBe('Show Name Special - S01E01 - Pilot Part One 1080p E-AC-3 5.1 H.265');
});

it('generates season and serie folder names', function () {
    $serie = Series::factory()->create(['name' => 'Show: Name / Special']);
    $season = Season::factory()->for($serie, 'series')->create(['season_number' => 2]);

    expect($this->service->generateSerieFolderName($serie))->toBe('Show Name Special')
        ->and($this->service->generateSeasonFolderName($season))->toBe('Season 02');
});

it('generates full strm paths', function () {
    $serie = Series::factory()->create(['name' => 'Show Name']);
    $season = Season::factory()->for($serie, 'series')->create(['season_number' => 3]);
    $episode = Episode::factory()
        ->for($serie, 'series')
        ->for($season)
        ->create([
            'title' => 'Episode Title',
            'season' => 3,
            'episode_num' => 4,
            'stream_stats' => [],
        ]);
    $setting = new StreamFileSetting([
        'episode_format' => '{title} - S{season}E{episode} - {ep_title}',
    ]);

    $path = $this->service->generateFullPath($episode, $setting);

    expect($path)->toBe('Show Name/Season 03/Show Name - S03E04 - Episode Title.strm');
});
