<?php

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Jobs\ReleaseWatchedManagedCache;
use App\Models\CachedContentFile;
use App\Models\Episode;
use App\Models\ManagedCacheInterest;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentActivityService;
use App\Services\CachedContentRetentionService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Storage::fake('cache');
    config(['cache.default' => 'array']);
    $settings = Mockery::mock(GeneralSettings::class);
    $settings->shouldReceive('refresh')->zeroOrMoreTimes()->andReturnSelf();
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->integration = MediaServerIntegration::factory()->for($this->user)->createQuietly(['type' => 'emby']);
});

function lifecycleEpisode(Playlist $playlist, string $episode): CachedContentFile
{
    return CachedContentFile::factory()->for($playlist)->createQuietly([
        'status' => CachedContentFileStatus::Completed,
        'managed_by' => CachedContentManagedBy::ManagedPublishing,
        'source' => 'provider',
        'disk' => 'cache',
        'file_path' => "managed/{$episode}.mp4",
        'file_size_bytes' => 11,
        'content_fingerprint' => "fixture-{$episode}",
    ]);
}

function lifecycleInterest(CachedContentFile $file, MediaServerIntegration $integration, string $episode, string $consumer, ?DateTimeInterface $releaseAfter): void
{
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => "episode:fixture:{$episode}",
        'variant_key' => '1080p',
        'consumer_id' => $consumer,
        'watched_at' => $releaseAfter ? now()->subHour() : null,
        'release_after' => $releaseAfter,
    ]);
}

it('removes only a watched completed episode while retaining an active next episode', function () {
    [$episodeOne, $episodeTwo, $episodeThree] = [
        lifecycleEpisode($this->playlist, 's01e01'),
        lifecycleEpisode($this->playlist, 's01e02'),
        lifecycleEpisode($this->playlist, 's01e03'),
    ];

    foreach ([$episodeOne, $episodeTwo, $episodeThree] as $episode) {
        Storage::disk('cache')->put($episode->file_path, 'video-bytes');
    }

    lifecycleInterest($episodeOne, $this->integration, 's01e01', 'profile-a', now()->subMinute());
    lifecycleInterest($episodeTwo, $this->integration, 's01e02', 'profile-a', now()->subMinute());
    lifecycleInterest($episodeThree, $this->integration, 's01e03', 'profile-a', null);

    app(CachedContentActivityService::class)->acquireReaderForFile($this->user, $this->integration, '44444444-4444-4444-8444-444444444444', $episodeTwo);

    app(ReleaseWatchedManagedCache::class)->handle(app(CachedContentRetentionService::class));

    expect(CachedContentFile::find($episodeOne->id))->toBeNull()
        ->and(Storage::disk('cache')->exists($episodeOne->file_path))->toBeFalse()
        ->and(CachedContentFile::find($episodeTwo->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists($episodeTwo->file_path))->toBeTrue()
        ->and(CachedContentFile::find($episodeThree->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists($episodeThree->file_path))->toBeTrue();
});

it('serves a completed managed episode cache by range and falls back after rewatch deletion', function () {
    $series = Series::factory()->create(['playlist_id' => $this->playlist->id, 'enabled' => true]);
    $season = Season::factory()->create(['series_id' => $series->id, 'playlist_id' => $this->playlist->id]);
    $episode = Episode::factory()->create([
        'series_id' => $series->id,
        'season_id' => $season->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'season' => 1,
        'episode_num' => 1,
    ]);
    $file = CachedContentFile::factory()->forItem($episode)->completed()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'managed_by' => CachedContentManagedBy::ManagedPublishing,
        'source' => 'provider',
        'disk' => 'cache',
        'file_path' => 'managed/s01e01.mp4',
    ]);
    Storage::disk('cache')->put($file->file_path, 'cache-bytes');

    $username = rawurlencode($this->user->name);
    $cacheRedirect = $this->get("/series/{$username}/{$this->playlist->uuid}/{$episode->id}.mp4");
    $cacheRedirect->assertRedirectContains("/cached-content/{$username}/{$this->playlist->uuid}/{$file->uuid}.mp4");

    $this->withHeaders(['Range' => 'bytes=0-4'])
        ->get("/cached-content/{$username}/{$this->playlist->uuid}/{$file->uuid}.mp4")
        ->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-4/11')
        ->assertStreamedContent('cache');

    Storage::disk('cache')->delete($file->file_path);

    $fallback = $this->get("/series/{$username}/{$this->playlist->uuid}/{$episode->id}.mp4");
    $fallback->assertRedirect();
    expect($fallback->headers->get('Location'))->not->toContain('/cached-content/');
});
