<?php

use App\Enums\CachedContentManagedBy;
use App\Enums\CacheDispatchResult;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Services\CachedContentDispatchService;
use App\Services\CachedContentRetentionService;
use App\Services\MediaSourceMatchService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Bind GeneralSettings with the requested enable_cache state.
 */
function dgacSettings(bool $enableCache = true): GeneralSettings
{
    $settings = new GeneralSettings;
    $settings->enable_cache = $enableCache;
    $settings->tmdb_api_key = 'fake-api-key';
    app()->instance(GeneralSettings::class, $settings);

    return $settings;
}

/**
 * A playlist + DynamicGroup whose dynamic_groups_config holds the matching
 * rule. Cache rule keys go in $ruleOverrides.
 *
 * @param  array<string, mixed>  $ruleOverrides
 * @return array{0: Playlist, 1: DynamicGroup}
 */
function dgacPlaylistWithGroup(string $type = 'vod', array $ruleOverrides = [], string $name = 'Trending Now', ?Playlist $playlist = null): array
{
    $rule = array_merge([
        'enabled' => true,
        'type' => $type,
        'source' => 'trending',
        'name' => $name,
        'tmdb_params' => [],
    ], $ruleOverrides);

    $playlist ??= Playlist::factory()->create();
    $playlist->update(['dynamic_groups_config' => [...($playlist->dynamic_groups_config ?? []), $rule]]);

    $group = DynamicGroup::factory()->for($playlist)->create([
        'user_id' => $playlist->user_id,
        'type' => $type,
        'source' => 'trending',
        'name' => $name,
    ]);

    return [$playlist, $group];
}

/**
 * A VOD channel with a cacheable URL on $playlist.
 *
 * @param  array<string, mixed>  $attributes
 */
function dgacChannel(Playlist $playlist, int $n, array $attributes = []): Channel
{
    return Channel::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'enabled' => true,
        'tmdb_id' => (string) (100 + $n),
        'url' => "https://provider.example.com/movie/{$n}.mkv",
        ...$attributes,
    ]);
}

/**
 * An enabled series on $playlist.
 */
function dgacSeries(Playlist $playlist): Series
{
    return Series::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'enabled' => true,
    ]);
}

/**
 * An enabled episode of $series with a cacheable URL.
 */
function dgacEpisode(Series $series, int $season, int $episodeNum): Episode
{
    return Episode::factory()->create([
        'user_id' => $series->user_id,
        'playlist_id' => $series->playlist_id,
        'series_id' => $series->id,
        'enabled' => true,
        'season' => $season,
        'episode_num' => $episodeNum,
        'url' => "https://provider.example.com/{$series->id}/s{$season}e{$episodeNum}.mkv",
    ]);
}

/**
 * Attach a Channel or Series member with an explicit TMDB-rank position.
 */
function dgacAttachMember(DynamicGroup $group, Channel|Series $item, int $position): void
{
    $relation = $item instanceof Channel ? 'channels' : 'series';
    $group->{$relation}()->attach($item->id, ['position' => $position]);
}

/**
 * A completed, group-managed cached file for $item, linked to $group.
 */
function dgacManagedFile(Channel|Episode $item, DynamicGroup $group, ?Carbon $droppedAt = null): CachedContentFile
{
    $file = CachedContentFile::factory()->completed()->dynamicGroupManaged()->forItem($item)->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'bytes');
    $file->dynamicGroups()->attach($group->id, ['dropped_at' => $droppedAt]);

    return $file;
}

/**
 * Give $channel an eligible media-server match: a media playlist with an
 * enabled emby integration and an enabled media channel sharing the
 * provider channel's tmdb_id.
 */
function dgacMatchToMedia(Playlist $provider, Channel $channel): void
{
    $media = Playlist::factory()->for($provider->user)->create();
    Channel::factory()->for($media)->for($provider->user)->create([
        'enabled' => true,
        'is_vod' => true,
        'tmdb_id' => $channel->tmdb_id,
        'url' => 'https://media.example.com/local/'.$channel->id.'.mkv',
    ]);
    MediaServerIntegration::factory()->for($provider->user)->create([
        'type' => 'emby',
        'enabled' => true,
        'playlist_id' => $media->id,
    ]);

    app(MediaSourceMatchService::class)->rebuildForPlaylist($provider->refresh());
}

function dgacDispatch(DynamicGroup $group): array
{
    return app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group->fresh());
}

function dgacRelease(): int
{
    return app(CachedContentRetentionService::class)->releaseDynamicGroupCaches();
}

function dgacDroppedAt(CachedContentFile $file, DynamicGroup $group): ?string
{
    return DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->where('dynamic_group_id', $group->id)
        ->value('dropped_at');
}

beforeEach(function () {
    // Playlist::factory() fires PlaylistListener -> dispatch(ProcessM3uImport).
    Bus::fake();
    Storage::fake(CachedContentFile::DISK);
    Http::preventStrayRequests();
    dgacSettings(true);
});

// --- dispatchForDynamicGroup() ---

it('queues one group-managed download per VOD member and links the group', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    dgacAttachMember($group, dgacChannel($playlist, 1), 0);
    dgacAttachMember($group, dgacChannel($playlist, 2), 1);

    $counts = dgacDispatch($group);

    expect($counts[CacheDispatchResult::Queued->value])->toBe(2);
    Bus::assertDispatchedTimes(DownloadCachedContentFile::class, 2);

    $files = CachedContentFile::query()->where('managed_by', CachedContentManagedBy::DynamicGroup->value)->get();
    expect($files)->toHaveCount(2);
    foreach ($files as $file) {
        expect($file->dynamicGroups()->pluck('dynamic_groups.id')->all())->toBe([$group->id]);
    }
});

it('queues nothing when caching is off on the rule, the rule is disabled, or enable_cache is off', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod');
    dgacAttachMember($group, dgacChannel($playlist, 1), 0);
    expect(dgacDispatch($group)[CacheDispatchResult::Queued->value])->toBe(0);

    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['enabled' => false, 'cache_enabled' => true]);
    dgacAttachMember($group, dgacChannel($playlist, 2), 0);
    expect(dgacDispatch($group)[CacheDispatchResult::Queued->value])->toBe(0);

    dgacSettings(false);
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    dgacAttachMember($group, dgacChannel($playlist, 3), 0);
    expect(dgacDispatch($group)[CacheDispatchResult::Queued->value])->toBe(0);

    expect(CachedContentFile::count())->toBe(0);
});

it('caps members at cache_max_items in rank order, skipping disabled members', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true, 'cache_max_items' => 2]);
    dgacAttachMember($group, $top = dgacChannel($playlist, 1), 0);
    dgacAttachMember($group, $disabled = dgacChannel($playlist, 2, ['enabled' => false]), 1);
    dgacAttachMember($group, $second = dgacChannel($playlist, 3), 2);
    dgacAttachMember($group, $third = dgacChannel($playlist, 4), 3);

    $counts = dgacDispatch($group);

    expect($counts[CacheDispatchResult::Queued->value])->toBe(2)
        ->and(CachedContentFile::pluck('cacheable_id')->sort()->values()->all())->toBe([$top->id, $second->id])
        ->and(CachedContentFile::where('cacheable_id', $disabled->id)->exists())->toBeFalse()
        ->and(CachedContentFile::where('cacheable_id', $third->id)->exists())->toBeFalse();
});

it('caches only the latest season of each member series', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('series', ['cache_enabled' => true]);
    $series = dgacSeries($playlist);
    dgacEpisode($series, 1, 1);
    dgacEpisode($series, 1, 2);
    $latest = dgacEpisode($series, 2, 1);
    dgacAttachMember($group, $series, 0);

    $counts = dgacDispatch($group);

    expect($counts[CacheDispatchResult::Queued->value])->toBe(1)
        ->and(CachedContentFile::sole()->cacheable_id)->toBe($latest->id);
});

it('does not link a manual cached file', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    $file = CachedContentFile::factory()->completed()->forItem($channel)->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'bytes');
    dgacAttachMember($group, $channel, 0);

    $counts = dgacDispatch($group);

    expect($counts[CacheDispatchResult::AlreadyCached->value])->toBe(1)
        ->and(DB::table('cached_content_file_dynamic_groups')->count())->toBe(0)
        ->and($file->fresh()->managed_by)->toBeNull();
});

it('clears dropped_at when a member returns to the group', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true, 'cache_keep_days' => 7]);
    $channel = dgacChannel($playlist, 1);
    dgacAttachMember($group, $channel, 0);
    $file = dgacManagedFile($channel, $group, droppedAt: now()->subDays(3));

    dgacDispatch($group);

    expect(dgacDroppedAt($file, $group))->toBeNull();
});

it('leaves a failed group-managed row alone, while manual dispatch re-queues it', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    CachedContentFile::factory()->failed()->dynamicGroupManaged()->forItem($channel)->create();
    dgacAttachMember($group, $channel, 0);

    expect(dgacDispatch($group)[CacheDispatchResult::Queued->value])->toBe(0);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);

    expect(app(CachedContentDispatchService::class)->dispatch($channel))->toBe(CacheDispatchResult::Queued);
    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('makes a group-managed row manual on manual dispatch', function () {
    $playlist = Playlist::factory()->create();
    $channel = dgacChannel($playlist, 1);
    $file = CachedContentFile::factory()->dynamicGroupManaged()->forItem($channel)->create();

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::AlreadyQueued)
        ->and($file->fresh()->managed_by)->toBeNull();
});

it('keeps files a Never expire rule caches, including ones it cached earlier', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true, 'cache_never_expire' => true]);
    $new = dgacChannel($playlist, 1);
    $earlier = dgacChannel($playlist, 2);
    dgacAttachMember($group, $new, 0);
    dgacAttachMember($group, $earlier, 1);
    $earlierFile = dgacManagedFile($earlier, $group);

    dgacDispatch($group);

    expect(CachedContentFile::where('cacheable_id', $new->id)->sole()->managed_by)->toBeNull()
        ->and($earlierFile->fresh()->managed_by)->toBeNull();

    // Both leave the group; cleanup keeps them.
    DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->delete();

    expect(dgacRelease())->toBe(0)
        ->and(CachedContentFile::count())->toBe(2);
});

// --- local media wins ---

it('skips a member that is on the media server', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $playlist->update(['prefer_media_server_sources' => true]);
    $matched = dgacChannel($playlist, 1);
    dgacAttachMember($group, $matched, 0);
    dgacMatchToMedia($playlist, $matched);

    expect(dgacDispatch($group)[CacheDispatchResult::Queued->value])->toBe(0)
        ->and(CachedContentFile::count())->toBe(0);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('still queues a matched member when the playlist does not prefer media sources', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $matched = dgacChannel($playlist, 1);
    dgacAttachMember($group, $matched, 0);
    dgacMatchToMedia($playlist, $matched);

    expect(dgacDispatch($group)[CacheDispatchResult::Queued->value])->toBe(1);
});

it('manual dispatch still caches a media-matched item', function () {
    $playlist = Playlist::factory()->create(['prefer_media_server_sources' => true]);
    $matched = dgacChannel($playlist, 1);
    dgacMatchToMedia($playlist, $matched);

    expect(app(CachedContentDispatchService::class)->dispatch($matched))->toBe(CacheDispatchResult::Queued);
});

it('releases a group copy once the member appears on the media server', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    dgacAttachMember($group, $channel, 0);
    $file = dgacManagedFile($channel, $group);

    $playlist->update(['prefer_media_server_sources' => true]);
    dgacMatchToMedia($playlist, $channel);
    dgacDispatch($group);

    expect(dgacRelease())->toBe(1)
        ->and(CachedContentFile::find($file->id))->toBeNull();
});

// --- releaseDynamicGroupCaches() ---

it('releases a file once its channel leaves the group, and keeps it while a member', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $stays = dgacChannel($playlist, 1);
    $leaves = dgacChannel($playlist, 2);
    dgacAttachMember($group, $stays, 0);
    $kept = dgacManagedFile($stays, $group);
    $released = dgacManagedFile($leaves, $group);

    expect(dgacRelease())->toBe(1)
        ->and($kept->fresh())->not->toBeNull()
        ->and(CachedContentFile::find($released->id))->toBeNull()
        ->and(Storage::disk(CachedContentFile::DISK)->exists($released->file_path))->toBeFalse();
});

it('keeps a file for cache_keep_days after it leaves the group', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true, 'cache_keep_days' => 7]);
    $file = dgacManagedFile(dgacChannel($playlist, 1), $group);

    expect(dgacRelease())->toBe(0)
        ->and(dgacDroppedAt($file, $group))->not->toBeNull();

    Carbon::setTestNow(now()->addDays(6));
    expect(dgacRelease())->toBe(0);

    Carbon::setTestNow(now()->addDays(2));
    expect(dgacRelease())->toBe(1)
        ->and(CachedContentFile::find($file->id))->toBeNull();
});

it('releases files outside the top N or of disabled members', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true, 'cache_max_items' => 1]);
    dgacAttachMember($group, $top = dgacChannel($playlist, 1), 0);
    dgacAttachMember($group, $second = dgacChannel($playlist, 2), 1);
    dgacAttachMember($group, $disabled = dgacChannel($playlist, 3, ['enabled' => false]), 2);
    $kept = dgacManagedFile($top, $group);
    dgacManagedFile($second, $group);
    dgacManagedFile($disabled, $group);

    expect(dgacRelease())->toBe(2)
        ->and(CachedContentFile::sole()->id)->toBe($kept->id);
});

it('releases older-season episodes once a series gets a new season', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('series', ['cache_enabled' => true]);
    $series = dgacSeries($playlist);
    dgacAttachMember($group, $series, 0);
    $oldSeason = dgacManagedFile(dgacEpisode($series, 1, 1), $group);

    expect(dgacRelease())->toBe(0);

    $newSeason = dgacManagedFile(dgacEpisode($series, 2, 1), $group);

    expect(dgacRelease())->toBe(1)
        ->and(CachedContentFile::find($oldSeason->id))->toBeNull()
        ->and($newSeason->fresh())->not->toBeNull();
});

it('releases files once caching is turned off on the rule', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    dgacAttachMember($group, $channel, 0);
    $file = dgacManagedFile($channel, $group);

    $config = $playlist->fresh()->dynamic_groups_config;
    $config[0]['cache_enabled'] = false;
    $playlist->update(['dynamic_groups_config' => $config]);

    expect(dgacRelease())->toBe(1)
        ->and(CachedContentFile::find($file->id))->toBeNull();
});

it('keeps a managed file while another group still holds it', function () {
    [$playlist, $groupA] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    [, $groupB] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true], name: 'Second Group', playlist: $playlist);
    $channel = dgacChannel($playlist, 1);
    dgacAttachMember($groupB, $channel, 0);
    $file = dgacManagedFile($channel, $groupA);
    $file->dynamicGroups()->attach($groupB->id);

    expect(dgacRelease())->toBe(0)
        ->and($file->fresh())->not->toBeNull()
        ->and($file->dynamicGroups()->pluck('dynamic_groups.id')->all())->toBe([$groupB->id]);
});

it('releases a deleted group\'s files at the next cleanup', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    dgacAttachMember($group, $channel, 0);
    $file = dgacManagedFile($channel, $group);

    // Query-builder delete, matching SyncDynamicGroups' stale cleanup.
    DynamicGroup::whereKey($group->id)->delete();

    expect(dgacRelease())->toBe(1)
        ->and(CachedContentFile::find($file->id))->toBeNull();
});

it('holds a renamed rule\'s files until its new group re-links them', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    dgacAttachMember($group, $channel, 0);
    $file = dgacManagedFile($channel, $group);

    // Renamed in the playlist form; the old group survives until the next sync.
    $config = $playlist->fresh()->dynamic_groups_config;
    $config[0]['name'] = 'Renamed Group';
    $playlist->update(['dynamic_groups_config' => $config]);

    // The 03:00 cleanup runs before the 04:15 refresh.
    expect(dgacRelease())->toBe(0)
        ->and($file->fresh())->not->toBeNull();

    // The refresh materializes the renamed group, links the file, and
    // deletes the old group.
    $renamed = DynamicGroup::factory()->for($playlist)->create([
        'user_id' => $playlist->user_id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Renamed Group',
    ]);
    dgacAttachMember($renamed, $channel, 0);
    expect(dgacDispatch($renamed)[CacheDispatchResult::AlreadyCached->value])->toBe(1);
    DynamicGroup::whereKey($group->id)->delete();

    Carbon::setTestNow(now()->addDays(2));
    expect(dgacRelease())->toBe(0)
        ->and($file->dynamicGroups()->pluck('dynamic_groups.id')->all())->toBe([$renamed->id]);
});

it('releases a removed rule\'s files after a day', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $file = dgacManagedFile(dgacChannel($playlist, 1), $group);
    $playlist->update(['dynamic_groups_config' => []]);

    expect(dgacRelease())->toBe(0);

    Carbon::setTestNow(now()->addDay()->addMinute());
    expect(dgacRelease())->toBe(1)
        ->and(CachedContentFile::find($file->id))->toBeNull();
});

it('never deletes a manual file via group retention', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $file = CachedContentFile::factory()->completed()->forItem(dgacChannel($playlist, 1))->create();
    $file->dynamicGroups()->attach($group->id);

    expect(dgacRelease())->toBe(0)
        ->and($file->fresh())->not->toBeNull();
});

it('never deletes Pending or Downloading files', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $pending = CachedContentFile::factory()->dynamicGroupManaged()->forItem(dgacChannel($playlist, 1))->create();
    $downloading = CachedContentFile::factory()->downloading()->dynamicGroupManaged()->forItem(dgacChannel($playlist, 2))->create();
    $pending->dynamicGroups()->attach($group->id);
    $downloading->dynamicGroups()->attach($group->id);

    expect(dgacRelease())->toBe(0)
        ->and($pending->fresh())->not->toBeNull()
        ->and($downloading->fresh())->not->toBeNull();
});
