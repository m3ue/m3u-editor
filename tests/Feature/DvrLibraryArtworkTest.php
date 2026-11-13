<?php

/**
 * Library presentation of DVR recordings no metadata provider matched: generated
 * poster/backdrop art with the channel logo as the title logo, a synopsis and
 * runtime from the recording, and series that move up "Recently Updated".
 */

use App\Enums\ChannelLogoType;
use App\Jobs\FetchTmdbIds;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\EpgChannel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\DvrArtworkService;
use App\Services\DvrVodIntegrationService;
use App\Services\LogoCacheService;
use App\Services\LogoService;
use App\Services\TmdbService;
use App\Support\DvrLibraryFallback;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

const DVR_ART_LOGO = 'https://logos.example.com/espn-plus-58.png';

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->setting = DvrSetting::factory()->enabled()->for($this->user)->for($this->playlist)->create();
    $this->source = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'is_vod' => false,
        'title' => 'ESPN+ 58',
        'logo' => null,
        'logo_type' => ChannelLogoType::Channel,
        'logo_internal' => DVR_ART_LOGO,
    ]);
    $this->service = app(DvrVodIntegrationService::class);

    // The channel logo, as the logo proxy would already have cached it.
    LogoCacheService::storeImage(LogoCacheService::sourceKeyForUrl(DVR_ART_LOGO), pngLogo(), 'image/png');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function dvrArtRecording($test, array $overrides = []): DvrRecording
{
    return DvrRecording::factory()->completed()->for($test->user)->for($test->setting)->create([
        'channel_id' => $test->source->id,
        'title' => 'Fairfield vs. Seton Hall',
        'season' => null,
        'episode' => null,
        'metadata' => null,
        'description' => null,
        'duration_seconds' => 7384,
        'programme_start' => Carbon::parse('2026-11-12 19:00:00'),
        'epg_programme_data' => ['category' => 'TV Movie'],
        ...$overrides,
    ]);
}

function pngLogo(): string
{
    $image = imagecreatetruecolor(120, 60);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('gives an unmatched movie generated art, the channel logo as title logo, and recording details', function () {
    $recording = dvrArtRecording($this);

    $this->service->integrateRecording($recording);
    $info = Channel::where('dvr_recording_id', $recording->id)->sole()->info;
    $channel = Channel::where('dvr_recording_id', $recording->id)->sole();

    expect($channel->logo)->toContain('/dvr/artwork/poster/')
        ->and($info['movie_image'])->toBe($channel->logo)
        ->and($info['cover_big'])->toBe($channel->logo)
        ->and($info['backdrop_path'])->toHaveCount(1)
        ->and($info['backdrop_path'][0])->toContain('/dvr/artwork/backdrop/')
        ->and($info['clearlogo'])->toBe(DVR_ART_LOGO)
        ->and($info['genre'])->toBe('TV Movie')
        ->and($info['duration_secs'])->toBe(7384)
        ->and($info['duration'])->toBe('02:03:04')
        ->and($info['plot'])->toStartWith('Recorded ')
        ->and($info['plot'])->toEndWith(' on ESPN+ 58.');
});

it('keeps provider art and the EPG description when there is metadata', function () {
    $recording = dvrArtRecording($this, [
        'description' => 'From the EPG.',
        'metadata' => ['tmdb' => ['id' => 1, 'type' => 'movie', 'name' => 'Matched', 'poster_url' => 'https://tmdb.test/p.jpg']],
    ]);

    $this->service->integrateRecording($recording);
    $info = Channel::where('dvr_recording_id', $recording->id)->sole()->info;

    expect($info['movie_image'])->toBe('https://tmdb.test/p.jpg')
        ->and($info)->not->toHaveKeys(['clearlogo', 'backdrop_path', 'genre']);
});

it('uses the matched poster as the movie cover and its backdrop as the backdrop', function () {
    $recording = dvrArtRecording($this, [
        'metadata' => ['tmdb' => ['id' => 793447, 'type' => 'movie', 'name' => 'The Moon and Back', 'poster_url' => 'https://tmdb.test/poster.jpg', 'backdrop_url' => 'https://tmdb.test/backdrop.jpg']],
    ]);

    $this->service->integrateRecording($recording);
    $info = Channel::where('dvr_recording_id', $recording->id)->sole()->info;

    expect($info['cover_big'])->toBe('https://tmdb.test/poster.jpg')
        ->and($info['movie_image'])->toBe('https://tmdb.test/poster.jpg')
        ->and($info['backdrop_path'])->toBe(['https://tmdb.test/backdrop.jpg'])
        ->and($info[DvrLibraryFallback::KEY] ?? [])->not->toContain('cover_big', 'backdrop_path');
});

it('gives a movie matched without a poster generated art instead of the bare channel logo', function () {
    $recording = dvrArtRecording($this, [
        'metadata' => ['tmdb' => ['id' => 257469, 'type' => 'movie', 'name' => 'Signing Off', 'overview' => 'Plot.', 'poster_url' => null, 'backdrop_url' => null]],
    ]);

    $this->service->integrateRecording($recording);
    $channel = Channel::where('dvr_recording_id', $recording->id)->sole();
    $poster = app(DvrArtworkService::class)->url(DvrArtworkService::POSTER, DVR_ART_LOGO);

    expect($channel->tmdb_id)->toBe(257469)
        ->and($channel->logo)->toBe($poster)
        ->and($channel->info['movie_image'])->toBe($poster)
        ->and($channel->info['cover_big'])->toBe($poster)
        ->and($channel->info['backdrop_path'])->toBe([app(DvrArtworkService::class)->url(DvrArtworkService::BACKDROP, DVR_ART_LOGO)])
        ->and($channel->info['clearlogo'])->toBe(DVR_ART_LOGO)
        ->and($channel->info['plot'])->toBe('Plot.')
        ->and($channel->info[DvrLibraryFallback::KEY])->toContain('movie_image', 'backdrop_path', 'clearlogo', 'logo')->not->toContain('plot');
});

it('stores the backdrop of a series matched when it is created, so the home screen has it', function () {
    $this->service->integrateRecording(dvrArtRecording($this, [
        'title' => 'South Park', 'season' => 27, 'episode' => 1, 'epg_programme_data' => ['category' => 'Comedy'],
        'metadata' => ['tmdb' => ['id' => 2190, 'type' => 'tv', 'name' => 'South Park', 'poster_url' => 'https://tmdb.test/poster.jpg', 'backdrop_url' => 'https://tmdb.test/backdrop.jpg']],
    ]));

    $series = Series::where('import_batch_no', 'dvr')->sole();

    expect($series->cover)->toBe('https://tmdb.test/poster.jpg')
        ->and($series->backdrop_path)->toBe(['https://tmdb.test/backdrop.jpg'])
        ->and($series->metadata)->not->toHaveKeys(['clearlogo', DvrLibraryFallback::KEY]);
});

it('gives a series matched without a poster generated stand-in art', function () {
    $this->service->integrateRecording(dvrArtRecording($this, [
        'title' => 'Obscure Show', 'season' => 1, 'episode' => 1, 'epg_programme_data' => ['category' => 'Drama'],
        'metadata' => ['tmdb' => ['id' => 99, 'type' => 'tv', 'name' => 'Obscure Show', 'poster_url' => null, 'backdrop_url' => 'https://tmdb.test/backdrop.jpg']],
    ]));

    $series = Series::where('import_batch_no', 'dvr')->sole();

    expect($series->cover)->toBe(app(DvrArtworkService::class)->url(DvrArtworkService::POSTER, DVR_ART_LOGO))
        ->and($series->backdrop_path)->toBe(['https://tmdb.test/backdrop.jpg'])
        ->and($series->metadata['clearlogo'])->toBe(DVR_ART_LOGO)
        ->and($series->metadata[DvrLibraryFallback::KEY])->toBe(['cover', 'clearlogo']);
});

it('keeps stand-in art a later match does not replace, and drops the channel title logo once a poster arrives', function () {
    $recording = fn (int $episode, ?array $tmdb) => dvrArtRecording($this, [
        'title' => 'Late Match', 'season' => 1, 'episode' => $episode, 'epg_programme_data' => ['category' => 'Drama'],
        'metadata' => $tmdb ? ['tmdb' => $tmdb] : null,
    ]);
    $this->service->integrateRecording($recording(1, null));

    $this->service->integrateRecording($recording(2, ['id' => 5, 'type' => 'tv', 'name' => 'Late Match', 'poster_url' => 'https://tmdb.test/poster.jpg', 'backdrop_url' => null]));

    $series = Series::where('import_batch_no', 'dvr')->sole();
    expect($series->cover)->toBe('https://tmdb.test/poster.jpg')
        ->and($series->backdrop_path)->toBe([app(DvrArtworkService::class)->url(DvrArtworkService::BACKDROP, DVR_ART_LOGO)])
        ->and($series->metadata)->not->toHaveKey('clearlogo')
        ->and($series->metadata[DvrLibraryFallback::KEY])->toBe(['backdrop_path']);
});

/**
 * Binds a TmdbService double whose details lookups return [$logo] as the
 * title logo, and rebuilds the integration service with it.
 */
function withTmdbTitleLogo($test, ?string $logo): MockInterface
{
    $tmdb = Mockery::mock(TmdbService::class)->shouldIgnoreMissing();
    $tmdb->allows('isConfigured')->andReturn(true);
    $tmdb->allows('getTvSeriesDetails')->andReturn(['logo_url' => $logo]);
    $tmdb->allows('getMovieDetails')->andReturn(['logo_url' => $logo]);
    app()->instance(TmdbService::class, $tmdb);
    $test->service = app(DvrVodIntegrationService::class);

    return $tmdb;
}

it('stores the TMDB title logo when a matched series is added, looking it up once per series', function () {
    $tmdb = withTmdbTitleLogo($this, 'https://tmdb.test/logo.png');
    $southPark = ['tmdb' => ['id' => 2190, 'type' => 'tv', 'name' => 'South Park', 'poster_url' => 'https://tmdb.test/poster.jpg']];

    foreach ([1, 2] as $episode) {
        $this->service->integrateRecording(dvrArtRecording($this, [
            'title' => 'South Park', 'season' => 27, 'episode' => $episode, 'epg_programme_data' => ['category' => 'Comedy'],
            'metadata' => $southPark,
        ]));
    }

    expect(Series::where('import_batch_no', 'dvr')->sole()->metadata['clearlogo'])->toBe('https://tmdb.test/logo.png');
    $tmdb->shouldHaveReceived('getTvSeriesDetails')->once()->with(2190);
});

it('replaces the channel-logo stand-in with the TMDB title logo for a series matched without a poster', function () {
    withTmdbTitleLogo($this, 'https://tmdb.test/logo.png');

    $this->service->integrateRecording(dvrArtRecording($this, [
        'title' => 'Obscure Show', 'season' => 1, 'episode' => 1, 'epg_programme_data' => ['category' => 'Drama'],
        'metadata' => ['tmdb' => ['id' => 99, 'type' => 'tv', 'name' => 'Obscure Show', 'poster_url' => null]],
    ]));

    $metadata = Series::where('import_batch_no', 'dvr')->sole()->metadata;
    expect($metadata['clearlogo'])->toBe('https://tmdb.test/logo.png')
        ->and($metadata[DvrLibraryFallback::KEY])->not->toContain('clearlogo');
});

it('stores the TMDB title logo when a matched movie is added', function () {
    $tmdb = withTmdbTitleLogo($this, 'https://tmdb.test/movie-logo.png');
    $recording = dvrArtRecording($this, [
        'metadata' => ['tmdb' => ['id' => 42, 'type' => 'movie', 'name' => 'Matched', 'poster_url' => 'https://tmdb.test/p.jpg']],
    ]);

    $this->service->integrateRecording($recording);
    $this->service->integrateRecording($recording->fresh());

    $info = Channel::where('dvr_recording_id', $recording->id)->sole()->info;
    expect($info['clearlogo'])->toBe('https://tmdb.test/movie-logo.png')
        ->and($info[DvrLibraryFallback::KEY] ?? [])->not->toContain('clearlogo');
    // A re-integration keeps the logo rather than looking it up again.
    $tmdb->shouldHaveReceived('getMovieDetails')->once()->with(42);
});

it('leaves the title logo empty when TMDB has none for the match', function () {
    withTmdbTitleLogo($this, null);

    $this->service->integrateRecording(dvrArtRecording($this, [
        'title' => 'South Park', 'season' => 27, 'episode' => 1, 'epg_programme_data' => ['category' => 'Comedy'],
        'metadata' => ['tmdb' => ['id' => 2190, 'type' => 'tv', 'name' => 'South Park', 'poster_url' => 'https://tmdb.test/poster.jpg']],
    ]));

    expect(Series::where('import_batch_no', 'dvr')->sole()->metadata)->not->toHaveKey('clearlogo');
});

it('gives an unmatched series generated art and moves it up when a new episode lands', function () {
    $this->travelTo(Carbon::parse('2026-11-12 22:00:00'));
    $first = dvrArtRecording($this, ['title' => 'Catfish', 'season' => 6, 'episode' => 7, 'description' => 'Episode seven.']);
    $this->service->integrateRecording($first);

    $series = Series::sole();
    $episode = Episode::where('dvr_recording_id', $first->id)->sole();

    expect($series->cover)->toContain('/dvr/artwork/poster/')
        ->and($series->backdrop_path[0])->toContain('/dvr/artwork/backdrop/')
        ->and($series->metadata['clearlogo'])->toBe(DVR_ART_LOGO)
        ->and($series->last_modified->toDateTimeString())->toBe('2026-11-12 22:00:00')
        ->and($episode->info['movie_image'])->toBe($series->backdrop_path[0])
        ->and($episode->plot)->toBe('Episode seven.');

    // Re-integrating the same recording does not count as new.
    $this->travelTo(Carbon::parse('2026-11-13 08:00:00'));
    $this->service->integrateRecording($first);
    expect($series->fresh()->last_modified->toDateTimeString())->toBe('2026-11-12 22:00:00');

    $this->service->integrateRecording(dvrArtRecording($this, ['title' => 'Catfish', 'season' => 6, 'episode' => 8]));
    expect($series->fresh()->last_modified->toDateTimeString())->toBe('2026-11-13 08:00:00');
});

it('upgrades a series whose cover is still the bare channel logo', function () {
    $series = Series::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Catfish',
        'source_series_id' => null,
        'cover' => DVR_ART_LOGO,
        'metadata' => null,
    ]);

    $this->service->integrateRecording(dvrArtRecording($this, ['title' => 'Catfish', 'season' => 6, 'episode' => 8]));

    expect($series->fresh()->cover)->toContain('/dvr/artwork/poster/')
        ->and($series->fresh()->metadata['clearlogo'])->toBe(DVR_ART_LOGO);
});

it('renders the generated art from a logo the logo cache already holds, behind a signed URL', function () {
    $artwork = app(DvrArtworkService::class);

    $response = $this->get($artwork->url(DvrArtworkService::POSTER, DVR_ART_LOGO))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    expect(array_slice(getimagesizefromstring($response->streamedContent()), 0, 2))->toBe([600, 900]);

    // Cached: a second request serves the same file without rendering again.
    $file = $artwork->cachedFile(DvrArtworkService::POSTER, DVR_ART_LOGO);
    $this->get($artwork->url(DvrArtworkService::POSTER, DVR_ART_LOGO))->assertOk();
    expect($artwork->cachedFile(DvrArtworkService::POSTER, DVR_ART_LOGO))->toBe($file);
    Http::assertNothingSent();
});

it('refuses unsigned or tampered artwork URLs', function () {
    $url = app(DvrArtworkService::class)->url(DvrArtworkService::POSTER, DVR_ART_LOGO);

    $this->get(strtok($url, '?'))->assertForbidden();
    $this->get(str_replace('/poster/', '/backdrop/', $url))->assertForbidden();
});

it('still serves art when the logo cannot be fetched, and tries the logo again later', function () {
    // A literal public IP, so the private-network guard needs no DNS lookup.
    $logoUrl = 'https://93.184.215.14/missing-logo.png';
    Http::fake([$logoUrl => Http::sequence()->push('', 404)->push(pngLogo(), 200, ['Content-Type' => 'image/png'])]);
    $artwork = app(DvrArtworkService::class);
    $url = $artwork->url(DvrArtworkService::BACKDROP, $logoUrl);

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $withoutLogo = $artwork->cachedFile(DvrArtworkService::BACKDROP, $logoUrl);
    Http::assertSentCount(1);

    // Within the hour the logo-less art is reused without refetching.
    $this->travel(59)->minutes();
    $this->get($url)->assertOk();
    Http::assertSentCount(1);

    // After it the logo is fetched again, and the art rendered with it is kept.
    $this->travel(2)->minutes();
    $this->get($url)->assertOk();
    Http::assertSentCount(2);
    expect($artwork->cachedFile(DvrArtworkService::BACKDROP, $logoUrl))->not->toBe($withoutLogo);
});

it('leaves out the title logo when the channel logo cannot be fetched', function () {
    $logoUrl = 'https://93.184.215.14/unreachable-logo.png';
    Http::fake([$logoUrl => Http::response('', 403)]);
    $this->source->update(['logo_internal' => $logoUrl]);

    $this->service->integrateRecording(dvrArtRecording($this));
    $this->service->integrateRecording(dvrArtRecording($this, ['title' => 'Daily Show', 'season' => 1, 'episode' => 2, 'epg_programme_data' => ['category' => 'News']]));

    $movie = Channel::where('is_vod', true)->sole();
    $series = Series::where('import_batch_no', 'dvr')->sole();

    expect($movie->info)->not->toHaveKey('clearlogo')
        ->and($movie->info['movie_image'])->toContain('/dvr/artwork/poster/')
        ->and(DvrLibraryFallback::isFallback($movie->info, 'clearlogo'))->toBeFalse()
        ->and($series->metadata)->not->toHaveKey('clearlogo')
        ->and($series->cover)->toContain('/dvr/artwork/poster/');
});

it('caches a fetched logo under the type sniffed from its bytes, not the upstream Content-Type', function () {
    // A literal public IP, so the private-network guard needs no DNS lookup.
    $logoUrl = 'https://93.184.215.14/logo.png';
    Http::fake([$logoUrl => Http::response(pngLogo(), 200, ['Content-Type' => 'text/html'])]);

    $this->get(app(DvrArtworkService::class)->url(DvrArtworkService::POSTER, $logoUrl))->assertOk();

    expect(LogoCacheService::readCacheMetadata($logoUrl))
        ->toMatchArray(['extension' => 'png', 'content_type' => 'image/png']);
});

it('does not decode a logo whose dimensions exceed the pixel limit', function () {
    $png = oversizedPng(4200);

    expect(getimagesizefromstring($png)[0])->toBe(4200);

    $artwork = app(DvrArtworkService::class);
    expect($artwork->render(DvrArtworkService::POSTER, $png))
        ->toBe($artwork->render(DvrArtworkService::POSTER, null));
});

it('falls back to the EPG icon when the channel logo cannot be fetched', function () {
    $deadLogo = 'https://93.184.215.14/dead-provider-logo.jpg';
    $epgIcon = 'https://93.184.215.15/station-logo.png';
    Http::fake([
        $deadLogo => Http::response('', 403),
        $epgIcon => Http::response(pngLogo(), 200, ['Content-Type' => 'image/png']),
    ]);
    $this->source->update([
        'logo_internal' => $deadLogo,
        'logo_type' => ChannelLogoType::Channel,
        'epg_channel_id' => EpgChannel::factory()->for($this->user)->create(['icon' => $epgIcon])->id,
    ]);

    $this->service->integrateRecording(dvrArtRecording($this));

    $movie = Channel::where('is_vod', true)->sole();
    expect($movie->info['clearlogo'])->toBe($epgIcon)
        ->and($movie->info['movie_image'])->toBe(app(DvrArtworkService::class)->url(DvrArtworkService::POSTER, $epgIcon));

    // The dead logo was tried once, not again for the title logo.
    Http::assertSentCount(2);
});

it('uses the channel logo override ahead of the provider logo, and local logos as they are', function () {
    $override = 'https://logos.example.com/my-custom-logo.png';
    LogoCacheService::storeImage(LogoCacheService::sourceKeyForUrl($override), pngLogo(), 'image/png');
    $this->source->update(['logo' => $override]);

    $this->service->integrateRecording(dvrArtRecording($this));
    expect(Channel::where('is_vod', true)->sole()->info['clearlogo'])->toBe($override);

    $this->source->update(['logo' => 'storage/logos/uploaded.png']);
    $this->service->integrateRecording(dvrArtRecording($this, ['title' => 'Another Movie']));
    expect(Channel::where('is_vod', true)->where('title', 'like', 'Another Movie%')->sole()->logo)
        ->toBe('storage/logos/uploaded.png');
});

it('lists channel logos in the order the app shows them, preferred first', function () {
    $epgChannel = EpgChannel::factory()->for($this->user)->create(['icon' => 'https://epg.example.com/icon.png']);
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'logo' => null,
        'logo_internal' => 'https://provider.example.com/logo.png',
        'logo_type' => ChannelLogoType::Epg,
        'epg_channel_id' => $epgChannel->id,
    ]);

    expect(LogoService::channelLogoCandidates($channel))
        ->toBe(['https://epg.example.com/icon.png', 'https://provider.example.com/logo.png']);

    $channel->update(['logo_type' => ChannelLogoType::Channel]);
    expect(LogoService::channelLogoCandidates($channel->fresh()))
        ->toBe(['https://provider.example.com/logo.png', 'https://epg.example.com/icon.png']);

    $channel->update(['logo' => 'https://custom.example.com/override.png']);
    expect(LogoService::channelLogoCandidates($channel->fresh()))
        ->toBe(['https://custom.example.com/override.png', 'https://provider.example.com/logo.png', 'https://epg.example.com/icon.png'])
        ->and(LogoService::preferredChannelLogo($channel->fresh()))->toBe('https://custom.example.com/override.png');
});

/**
 * A TmdbService double for a later TMDB match: search finds [$tmdbId], details
 * carry real art, and season 6 has episode 7.
 */
function lateMatchTmdb(int $tmdbId): TmdbService
{
    $details = [
        'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
        'backdrop_url' => 'https://image.tmdb.org/t/p/original/backdrop.jpg',
        'logo_url' => 'https://image.tmdb.org/t/p/original/logo.png',
        'overview' => 'TMDB overview.',
        'genres' => 'Crime',
        'vote_average' => 7.4,
    ];

    $tmdb = Mockery::mock(TmdbService::class)->shouldIgnoreMissing();
    $tmdb->allows('isConfigured')->andReturn(true);
    $tmdb->allows('searchTvSeries')->andReturn(['tmdb_id' => $tmdbId]);
    $tmdb->allows('searchMovie')->andReturn(['tmdb_id' => $tmdbId]);
    $tmdb->allows('getTvSeriesDetails')->andReturn($details);
    $tmdb->allows('getMovieDetailsWithTvFallback')->andReturn([...$details, 'media_type' => 'movie']);
    $tmdb->allows('getAllSeasons')->andReturn([['season_number' => 6]]);
    $tmdb->allows('getSeasonDetailsResult')->andReturn(['status' => 'ok', 'payload' => ['episodes' => [
        ['id' => 99, 'episode_number' => 7, 'name' => 'Episode Seven', 'overview' => 'TMDB episode overview.', 'still_path' => '/still.jpg'],
    ]]]);

    return $tmdb;
}

it('lets a later TMDB match replace a DVR series stand-in poster, backdrop, title logo and episode plot', function () {
    $recording = dvrArtRecording($this, ['title' => 'Catfish', 'season' => 6, 'episode' => 7, 'description' => 'From the EPG.']);
    $this->service->integrateRecording($recording);
    $series = Series::sole();

    expect(DvrLibraryFallback::isFallback($series->metadata, 'cover'))->toBeTrue();

    (new FetchTmdbIds)->processSingleSeries(lateMatchTmdb(1001), $series);

    $series->refresh();
    $episode = Episode::where('dvr_recording_id', $recording->id)->sole();

    expect($series->cover)->toBe('https://image.tmdb.org/t/p/w500/poster.jpg')
        // FetchTmdbIds has always stored this JSON-encoded inside the array column.
        ->and(json_encode($series->backdrop_path))->toContain('backdrop.jpg')
        ->and($series->metadata['clearlogo'])->toBe('https://image.tmdb.org/t/p/original/logo.png')
        ->and($series->metadata)->not->toHaveKey(DvrLibraryFallback::KEY)
        ->and($episode->plot)->toBe('TMDB episode overview.')
        ->and($episode->info['plot'])->toBe('TMDB episode overview.')
        ->and($episode->info)->not->toHaveKey(DvrLibraryFallback::KEY);
});

it('searches a DVR movie by its recording title and lets a later match replace every stand-in', function () {
    $recording = dvrArtRecording($this, ['title' => 'Heat', 'description' => 'From the EPG.']);
    $this->service->integrateRecording($recording);
    $channel = Channel::where('dvr_recording_id', $recording->id)->sole();

    $tmdb = lateMatchTmdb(949);
    (new FetchTmdbIds)->processVodChannel($tmdb, $channel);

    $tmdb->shouldHaveReceived('searchMovie')->withArgs(fn (string $title) => $title === 'Heat');
    $channel->refresh();

    expect($channel->logo)->toBe('https://image.tmdb.org/t/p/w500/poster.jpg')
        ->and($channel->info['cover_big'])->toBe('https://image.tmdb.org/t/p/w500/poster.jpg')
        ->and($channel->info['movie_image'])->toBe('https://image.tmdb.org/t/p/w500/poster.jpg')
        ->and($channel->info['backdrop_path'])->toBe(['https://image.tmdb.org/t/p/original/backdrop.jpg'])
        ->and($channel->info['clearlogo'])->toBe('https://image.tmdb.org/t/p/original/logo.png')
        ->and($channel->info['plot'])->toBe('TMDB overview.')
        ->and($channel->info['genre'])->toBe('Crime')
        ->and($channel->info)->not->toHaveKey(DvrLibraryFallback::KEY);
});

it('keeps a real title logo and cover on a later TMDB match', function () {
    $series = Series::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Provider Show',
        'cover' => 'https://provider.test/cover.jpg',
        'plot' => null,
        'metadata' => ['clearlogo' => 'https://provider.test/logo.png'],
    ]);

    (new FetchTmdbIds)->processSingleSeries(lateMatchTmdb(1002), $series);

    expect($series->fresh()->cover)->toBe('https://provider.test/cover.jpg')
        ->and($series->fresh()->metadata['clearlogo'])->toBe('https://provider.test/logo.png');
});
