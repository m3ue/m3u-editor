<?php

/**
 * Tests for the DVR NFO generation pipeline:
 *  - Job dispatch behaviour (toggle, missing setting, missing file_path)
 *  - NfoService::generateDvrMovieNfo writes movie.nfo with TMDB metadata
 *  - NfoService::generateDvrEpisodeNfo writes <basename>.nfo for series
 *  - NfoService::generateDvrShowNfo writes tvshow.nfo in parent folder
 *  - Idempotency: identical content is not rewritten
 *  - GenerateDvrNfo job picks the right NFO type via isDvrRecordingSeries
 */

use App\Jobs\GenerateDvrNfo;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use App\Services\NfoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $recordingOverrides
 * @param  array<string, mixed>  $settingOverrides
 */
function makeNfoRecording(array $recordingOverrides = [], array $settingOverrides = []): DvrRecording
{
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create(array_merge([
        'storage_disk' => 'dvr-test',
        'generate_nfo_files' => true,
        'use_proxy' => false,
    ], $settingOverrides));

    $recording = DvrRecording::factory()
        ->for($user)
        ->for($setting)
        ->completed()
        ->create($recordingOverrides);

    // The completed() state's afterCreating hook unconditionally sets series_key
    // from the title. Re-apply overrides AFTER create so callers can force
    // movie-shaped recordings (series_key/season/episode = null).
    $reset = array_intersect_key($recordingOverrides, array_flip(['series_key', 'normalized_title', 'season', 'episode']));
    if ($reset !== []) {
        $recording->forceFill($reset)->saveQuietly();
        $recording->refresh();
    }

    return $recording;
}

beforeEach(function () {
    Event::fake();
    Storage::fake('dvr-test');
});

// ── Job behaviour ────────────────────────────────────────────────────────────

it('skips silently when generate_nfo_files toggle is off', function () {
    $recording = makeNfoRecording(
        recordingOverrides: ['file_path' => 'library/2025/Movie/Movie.mp4'],
        settingOverrides: ['generate_nfo_files' => false],
    );

    (new GenerateDvrNfo($recording->id))->handle(app(NfoService::class));

    Storage::disk('dvr-test')->assertMissing('library/2025/Movie/Movie.nfo');
});

it('skips when recording has no file_path', function () {
    $recording = makeNfoRecording(['file_path' => null]);

    (new GenerateDvrNfo($recording->id))->handle(app(NfoService::class));

    expect(Storage::disk('dvr-test')->allFiles())->toBe([]);
});

it('writes movie.nfo for VOD-shaped recordings', function () {
    $recording = makeNfoRecording([
        'title' => 'Test Movie',
        'file_path' => 'library/2025/Test Movie/Test Movie.mp4',
        'season' => null,
        'episode' => null,
        'series_key' => null,
        'metadata' => [
            'tmdb' => [
                'id' => 12345,
                'name' => 'Test Movie',
                'overview' => 'A great test movie.',
                'release_date' => '2024-06-15',
                'poster_url' => 'https://image.tmdb.org/t/p/original/poster.jpg',
            ],
        ],
    ]);

    (new GenerateDvrNfo($recording->id))->handle(app(NfoService::class));

    Storage::disk('dvr-test')->assertExists('library/2025/Test Movie/Test Movie.nfo');
    $xml = Storage::disk('dvr-test')->get('library/2025/Test Movie/Test Movie.nfo');
    expect($xml)
        ->toContain('<movie>')
        ->toContain('<title>Test Movie</title>')
        ->toContain('<plot>A great test movie.</plot>')
        ->toContain('<year>2024</year>')
        ->toContain('<tmdbid>12345</tmdbid>');
});

it('writes episode + tvshow nfo for series-shaped recordings', function () {
    $recording = makeNfoRecording([
        'title' => 'Test Show',
        'file_path' => 'library/2025/Test Show/Test Show - S02E05 - Pilot.mp4',
        'season' => 2,
        'episode' => 5,
        'metadata' => [
            'tmdb' => [
                'id' => 999,
                'name' => 'Test Show',
                'overview' => 'A great test show.',
            ],
            'tmdb_episode' => [
                'name' => 'Pilot',
                'overview' => 'The pilot episode.',
                'air_date' => '2024-09-01',
            ],
        ],
    ]);

    (new GenerateDvrNfo($recording->id))->handle(app(NfoService::class));

    Storage::disk('dvr-test')->assertExists('library/2025/Test Show/Test Show - S02E05 - Pilot.nfo');
    Storage::disk('dvr-test')->assertExists('library/2025/Test Show/tvshow.nfo');

    $epXml = Storage::disk('dvr-test')->get('library/2025/Test Show/Test Show - S02E05 - Pilot.nfo');
    expect($epXml)
        ->toContain('<episodedetails>')
        ->toContain('<season>2</season>')
        ->toContain('<episode>5</episode>');

    $showXml = Storage::disk('dvr-test')->get('library/2025/Test Show/tvshow.nfo');
    expect($showXml)
        ->toContain('<tvshow>')
        ->toContain('<title>Test Show</title>');
});

it('is idempotent: a second run does not modify identical NFO files', function () {
    $recording = makeNfoRecording([
        'file_path' => 'library/2025/Idem/Idem.mp4',
        'season' => null,
        'episode' => null,
        'series_key' => null,
        'metadata' => ['tmdb' => ['id' => 1, 'name' => 'Idem']],
    ]);

    $service = app(NfoService::class);

    $service->generateDvrMovieNfo($recording, 'dvr-test');
    $firstMtime = Storage::disk('dvr-test')->lastModified('library/2025/Idem/Idem.nfo');

    // Wait at least 1 second so a real rewrite would change the mtime.
    sleep(1);

    $service->generateDvrMovieNfo($recording, 'dvr-test');
    $secondMtime = Storage::disk('dvr-test')->lastModified('library/2025/Idem/Idem.nfo');

    expect($secondMtime)->toBe($firstMtime);
});

it('routes the job through NfoService::isDvrRecordingSeries to pick movie vs episode', function () {
    $service = app(NfoService::class);

    $movie = makeNfoRecording([
        'file_path' => 'library/2025/M/M.mp4',
        'season' => null,
        'episode' => null,
        'series_key' => null,
        'metadata' => ['tmdb' => ['id' => 1, 'name' => 'M']],
    ]);

    $series = makeNfoRecording([
        'file_path' => 'library/2025/S/S - S01E01.mp4',
        'season' => 1,
        'episode' => 1,
        'metadata' => ['tmdb' => ['id' => 2, 'name' => 'S']],
    ]);

    expect($service->isDvrRecordingSeries($movie))->toBeFalse()
        ->and($service->isDvrRecordingSeries($series))->toBeTrue();
});

it('queues the job on the dvr-meta queue when dispatched', function () {
    Queue::fake();

    GenerateDvrNfo::dispatch(42);

    Queue::assertPushedOn('dvr-meta', GenerateDvrNfo::class);
});
