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
 * Bind GeneralSettings with the requested enable_cache / TMDB state.
 */
function dgacSettings(bool $enableCache = true, ?string $tmdbApiKey = 'fake-api-key'): GeneralSettings
{
    $settings = new GeneralSettings;
    $settings->enable_cache = $enableCache;
    $settings->tmdb_api_key = $tmdbApiKey;
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
function dgacPlaylistWithGroup(string $type = 'vod', array $ruleOverrides = []): array
{
    $rule = array_merge([
        'enabled' => true,
        'type' => $type,
        'source' => 'trending',
        'name' => 'Trending Now',
        'tmdb_params' => [],
    ], $ruleOverrides);

    $playlist = Playlist::factory()->create();
    $playlist->update(['dynamic_groups_config' => [$rule]]);

    $group = DynamicGroup::factory()->for($playlist)->create([
        'user_id' => $playlist->user_id,
        'type' => $type,
        'source' => 'trending',
        'name' => 'Trending Now',
    ]);

    return [$playlist, $group];
}

/**
 * A VOD channel with a cacheable URL on $playlist.
 */
function dgacChannel(Playlist $playlist, int $n): Channel
{
    return Channel::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'tmdb_id' => (string) (100 + $n),
        'url' => "https://provider.example.com/movie/{$n}.mkv",
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
 * Attach a provenance pivot row carrying a retention snapshot.
 */
function dgacAttachPivot(CachedContentFile $file, ?DynamicGroup $group, string $retention, ?int $days = 7, ?string $droppedAt = null): void
{
    $file->dynamicGroups()->attach($group?->id, [
        'retention' => $retention,
        'retention_days' => $days,
        'dropped_at' => $droppedAt,
    ]);
}

/**
 * Give $channel an eligible media-server match: a media playlist with an
 * enabled emby integration and an enabled media channel sharing the
 * provider channel's tmdb_id (mirrors makeMatchedMovieFixture).
 */
function dgacMatchToMedia(Playlist $provider, Channel $channel): Channel
{
    $media = Playlist::factory()->for($provider->user)->create();
    $mediaChannel = Channel::factory()->for($media)->for($provider->user)->create([
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

    return $mediaChannel;
}

beforeEach(function () {
    // Playlist::factory() fires PlaylistListener -> dispatch(ProcessM3uImport).
    Bus::fake();
    Storage::fake(CachedContentFile::DISK);
    Http::preventStrayRequests();
    dgacSettings(true);
});

// --- dispatchForDynamicGroup(): queueing ---

it('queues one download per VOD member and attaches provenance', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    dgacAttachMember($group, dgacChannel($playlist, 1), 0);
    dgacAttachMember($group, dgacChannel($playlist, 2), 1);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::Queued->value])->toBe(2);
    Bus::assertDispatchedTimes(DownloadCachedContentFile::class, 2);

    $files = CachedContentFile::query()->where('managed_by', CachedContentManagedBy::DynamicGroup->value)->get();
    expect($files)->toHaveCount(2);

    foreach ($files as $file) {
        $pivot = DB::table('cached_content_file_dynamic_groups')
            ->where('cached_content_file_id', $file->id)
            ->where('dynamic_group_id', $group->id)
            ->first();

        expect($pivot)->not->toBeNull()
            ->and($pivot->retention)->toBe('in_group')
            ->and($pivot->retention_days)->toBe(7)
            ->and($pivot->dropped_at)->toBeNull();
    }
});

it('queues nothing when caching is off on the rule, the rule is disabled, or enable_cache is off', function () {
    // Rule without cache_enabled.
    [$playlist, $group] = dgacPlaylistWithGroup('vod');
    dgacAttachMember($group, dgacChannel($playlist, 1), 0);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);
    expect($counts[CacheDispatchResult::Queued->value])->toBe(0)
        ->and(CachedContentFile::count())->toBe(0);

    // Disabled rule with cache_enabled.
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['enabled' => false, 'cache_enabled' => true]);
    dgacAttachMember($group, dgacChannel($playlist, 2), 0);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);
    expect($counts[CacheDispatchResult::Queued->value])->toBe(0)
        ->and(CachedContentFile::count())->toBe(0);

    // Global enable_cache off.
    dgacSettings(false);
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    dgacAttachMember($group, dgacChannel($playlist, 3), 0);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);
    expect($counts[CacheDispatchResult::Disabled->value])->toBe(1)
        ->and(CachedContentFile::count())->toBe(0);
});

it('caps members by cache_max_items using TMDB rank order', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true, 'cache_max_items' => 2]);
    dgacAttachMember($group, $top = dgacChannel($playlist, 1), 0);
    dgacAttachMember($group, $second = dgacChannel($playlist, 2), 1);
    dgacAttachMember($group, $third = dgacChannel($playlist, 3), 2);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::Queued->value])->toBe(2)
        ->and(CachedContentFile::where('cacheable_id', $top->id)->exists())->toBeTrue()
        ->and(CachedContentFile::where('cacheable_id', $second->id)->exists())->toBeTrue()
        ->and(CachedContentFile::where('cacheable_id', $third->id)->exists())->toBeFalse();
});

it('stops queueing once the group budget is reached', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true, 'cache_max_gb' => 0.01]); // ~10.7 MB

    $seed = dgacChannel($playlist, 9);
    $seedFile = CachedContentFile::factory()->completed()->dynamicGroupManaged()->forItem($seed)->create([
        'file_size_bytes' => 20_000_000,
    ]);
    dgacAttachPivot($seedFile, $group, 'in_group');
    dgacAttachMember($group, $seed, 0);

    dgacAttachMember($group, $new = dgacChannel($playlist, 1), 1);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::Queued->value])->toBe(0)
        ->and(CachedContentFile::where('cacheable_id', $new->id)->exists())->toBeFalse();
});

it('caches only the latest season of each member series', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('series', ['cache_enabled' => true]);

    $series = Series::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'tmdb_id' => '1399',
    ]);
    $owner = ['user_id' => $playlist->user_id, 'playlist_id' => $playlist->id, 'series_id' => $series->id];
    Episode::factory()->create($owner + ['season' => 1, 'episode_num' => 1, 'url' => 'https://provider.example.com/s1e1.mkv']);
    Episode::factory()->create($owner + ['season' => 1, 'episode_num' => 2, 'url' => 'https://provider.example.com/s1e2.mkv']);
    $latest = Episode::factory()->create($owner + ['season' => 2, 'episode_num' => 1, 'url' => 'https://provider.example.com/s2e1.mkv']);

    dgacAttachMember($group, $series, 0);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::Queued->value])->toBe(1)
        ->and(CachedContentFile::query()->where('cacheable_id', $latest->id)->exists())->toBeTrue()
        ->and(CachedContentFile::count())->toBe(1);
});

it('does not attach provenance to a manual cached file', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    $file = CachedContentFile::factory()->completed()->forItem($channel)->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'bytes');
    dgacAttachMember($group, $channel, 0);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::AlreadyCached->value])->toBe(1)
        ->and(DB::table('cached_content_file_dynamic_groups')->count())->toBe(0)
        ->and($file->fresh()->managed_by)->toBeNull();
});

// --- local media always wins (media-server match) ---

it('skips a member whose item has an eligible media-server match', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $playlist->update(['prefer_media_server_sources' => true]);

    $matched = dgacChannel($playlist, 1);
    dgacAttachMember($group, $matched, 0);
    dgacMatchToMedia($playlist, $matched);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::MediaServerAvailable->value])->toBe(1)
        ->and($counts[CacheDispatchResult::Queued->value])->toBe(0)
        ->and(CachedContentFile::count())->toBe(0)
        ->and(DB::table('cached_content_file_dynamic_groups')->count())->toBe(0);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('still queues a matched member when the playlist does not prefer media sources', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    // Toggle stays off.

    $matched = dgacChannel($playlist, 1);
    dgacAttachMember($group, $matched, 0);
    dgacMatchToMedia($playlist, $matched);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::Queued->value])->toBe(1)
        ->and($counts[CacheDispatchResult::MediaServerAvailable->value])->toBe(0);
});

it('manual dispatch still caches a media-matched item', function () {
    $playlist = Playlist::factory()->create(['prefer_media_server_sources' => true]);
    $matched = dgacChannel($playlist, 1);
    dgacMatchToMedia($playlist, $matched);

    $result = app(CachedContentDispatchService::class)->dispatch($matched);

    expect($result)->toBe(CacheDispatchResult::Queued);
    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('releases a managed in_group file whose item gained an eligible media match', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    $file = CachedContentFile::factory()->completed()->dynamicGroupManaged()->forItem($channel)->create();
    dgacAttachPivot($file, $group, 'in_group');

    // The item stays a member but gains an eligible media match.
    $playlist->update(['prefer_media_server_sources' => true]);
    dgacMatchToMedia($playlist, $channel);

    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(1)
        ->and(CachedContentFile::find($file->id))->toBeNull()
        ->and($channel->exists)->toBeTrue(); // the member row itself is untouched
});

it('keeps a never_expire file whose item gained an eligible media match', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', [
        'cache_enabled' => true,
        'cache_retention' => 'never_expire',
    ]);
    $channel = dgacChannel($playlist, 1);
    $file = CachedContentFile::factory()->completed()->forItem($channel)->create(); // pinned
    dgacAttachPivot($file, $group, 'never_expire');

    $playlist->update(['prefer_media_server_sources' => true]);
    dgacMatchToMedia($playlist, $channel);

    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($file->fresh())->not->toBeNull();
});

// --- dispatch(automatic: true) cooldown / adoption ---

it('returns CoolingDown for a recently failed managed row on the automatic path', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    CachedContentFile::factory()->failed()->dynamicGroupManaged()->forItem($channel)->create([
        'last_failed_at' => now()->subHour(),
    ]);
    dgacAttachMember($group, $channel, 0);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::CoolingDown->value])->toBe(1)
        ->and($counts[CacheDispatchResult::Queued->value])->toBe(0);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('re-queues a failed managed row once the cooldown has elapsed', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    CachedContentFile::factory()->failed()->dynamicGroupManaged()->forItem($channel)->create([
        'last_failed_at' => now()->subDays(2),
    ]);
    dgacAttachMember($group, $channel, 0);

    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect($counts[CacheDispatchResult::Queued->value])->toBe(1);
    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('re-queues a recently failed row immediately on the manual path', function () {
    $playlist = Playlist::factory()->create();
    $channel = dgacChannel($playlist, 1);
    CachedContentFile::factory()->failed()->dynamicGroupManaged()->forItem($channel)->create([
        'last_failed_at' => now()->subHour(),
    ]);

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::Queued);
    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('pins never_expire files by clearing the managed flag after attaching', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', [
        'cache_enabled' => true,
        'cache_retention' => 'never_expire',
    ]);
    dgacAttachMember($group, dgacChannel($playlist, 1), 0);

    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    $file = CachedContentFile::sole();
    expect($file->managed_by)->toBeNull();

    $pivot = DB::table('cached_content_file_dynamic_groups')->where('cached_content_file_id', $file->id)->first();
    expect($pivot)->not->toBeNull()
        ->and($pivot->retention)->toBe('never_expire')
        ->and($pivot->dynamic_group_id)->toBe($group->id);
});

it('adopts a managed row on manual dispatch', function () {
    $playlist = Playlist::factory()->create();
    $channel = dgacChannel($playlist, 1);
    $file = CachedContentFile::factory()->forItem($channel)->create([
        'managed_by' => CachedContentManagedBy::DynamicGroup,
    ]);

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::AlreadyQueued)
        ->and($file->fresh()->managed_by)->toBeNull();
});

// --- retention: releaseDynamicGroupCaches() ---

it('releases an in_group file once its channel leaves the group', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    dgacAttachMember($group, $channel, 0);

    $file = CachedContentFile::factory()->completed()->dynamicGroupManaged()->forItem($channel)->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'bytes');
    dgacAttachPivot($file, $group, 'in_group');

    // Channel leaves the membership.
    DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->delete();

    $deleted = app(CachedContentRetentionService::class)->releaseDynamicGroupCaches();

    expect($deleted)->toBe(1)
        ->and(CachedContentFile::find($file->id))->toBeNull()
        ->and(Storage::disk(CachedContentFile::DISK)->exists($file->file_path))->toBeFalse();
});

it('keeps an in_group file while its channel is still a member', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    dgacAttachMember($group, $channel, 0);

    $file = CachedContentFile::factory()->completed()->dynamicGroupManaged()->forItem($channel)->create();
    dgacAttachPivot($file, $group, 'in_group');

    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($file->fresh())->not->toBeNull();
});

it('honors the in_group_plus_days grace period', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', [
        'cache_enabled' => true,
        'cache_retention' => 'in_group_plus_days',
        'cache_retention_days' => 7,
    ]);
    $channel = dgacChannel($playlist, 1);
    $file = CachedContentFile::factory()->completed()->dynamicGroupManaged()->forItem($channel)->create();
    dgacAttachPivot($file, $group, 'in_group_plus_days', 7, now()->subDays(3)->format('Y-m-d H:i:s'));

    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($file->fresh())->not->toBeNull();

    DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->update(['dropped_at' => now()->subDays(8)->format('Y-m-d H:i:s')]);

    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(1)
        ->and(CachedContentFile::find($file->id))->toBeNull();
});

it('keeps a never_expire file after the item left and the group was deleted', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', [
        'cache_enabled' => true,
        'cache_retention' => 'never_expire',
    ]);
    $channel = dgacChannel($playlist, 1);
    $file = CachedContentFile::factory()->completed()->forItem($channel)->create(); // pinned: not managed
    dgacAttachPivot($file, $group, 'never_expire');

    // Query-builder delete, matching SyncDynamicGroups' stale cleanup.
    DynamicGroup::whereKey($group->id)->delete();

    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($file->fresh())->not->toBeNull()
        ->and(DB::table('cached_content_file_dynamic_groups')->where('cached_content_file_id', $file->id)->count())->toBe(1);
});

it('keeps a managed file while another group still holds it', function () {
    [$playlist, $groupA] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);

    $ruleB = [
        'enabled' => true,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Second Group',
        'tmdb_params' => [],
        'cache_enabled' => true,
    ];
    $config = $playlist->fresh()->dynamic_groups_config;
    $config[] = $ruleB;
    $playlist->update(['dynamic_groups_config' => $config]);

    $groupB = DynamicGroup::factory()->for($playlist)->create([
        'user_id' => $playlist->user_id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Second Group',
    ]);

    $channel = dgacChannel($playlist, 1);
    $file = CachedContentFile::factory()->completed()->dynamicGroupManaged()->forItem($channel)->create();
    dgacAttachPivot($file, $groupA, 'in_group');
    dgacAttachPivot($file, $groupB, 'in_group');

    // Channel leaves only group A.
    DB::table('dynamic_group_items')->where('dynamic_group_id', $groupA->id)->delete();
    dgacAttachMember($groupB, $channel, 0);

    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($file->fresh())->not->toBeNull();
});

it('starts the grace period for NULL-group rows and releases them after it', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', [
        'cache_enabled' => true,
        'cache_retention' => 'in_group_plus_days',
        'cache_retention_days' => 7,
    ]);
    $channel = dgacChannel($playlist, 1);
    $file = CachedContentFile::factory()->completed()->dynamicGroupManaged()->forItem($channel)->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'bytes');
    dgacAttachPivot($file, $group, 'in_group_plus_days', 7);

    DynamicGroup::whereKey($group->id)->delete();

    // First sweep: the NULL-group row is stamped but inside the grace period.
    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($file->fresh())->not->toBeNull();

    Carbon::setTestNow(now()->addDays(3));
    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($file->fresh())->not->toBeNull();

    Carbon::setTestNow(now()->addDays(5)); // 8 days since the stamp
    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(1)
        ->and(CachedContentFile::find($file->id))->toBeNull();
});

it('gives NULL-group in_group rows a 24h floor before releasing them', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    $file = CachedContentFile::factory()->completed()->dynamicGroupManaged()->forItem($channel)->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'bytes');
    dgacAttachPivot($file, $group, 'in_group');

    DynamicGroup::whereKey($group->id)->delete();

    // First sweep stamps the NULL-group row but the 24h floor keeps the
    // file — the group may just be between the 03:00 sweep and the 04:15
    // refresh that would re-create it and re-adopt the file.
    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($file->fresh())->not->toBeNull();

    Carbon::setTestNow(now()->addHours(25));
    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(1)
        ->and(CachedContentFile::find($file->id))->toBeNull();
});

it('still releases a live group\'s in_group rows on the first sweep', function () {
    // The 24h floor applies only to NULL-group rows; a live group dropping
    // a member releases the file on the very next sweep.
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    $file = CachedContentFile::factory()->completed()->dynamicGroupManaged()->forItem($channel)->create();
    dgacAttachPivot($file, $group, 'in_group');

    // Member leaves but the group stays alive.
    DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->delete();

    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(1)
        ->and(CachedContentFile::find($file->id))->toBeNull();
});

it('neither stamps nor releases NULL-group rows while TMDB is unconfigured', function () {
    dgacSettings(true, tmdbApiKey: null);

    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    $file = CachedContentFile::factory()->completed()->dynamicGroupManaged()->forItem($channel)->create();
    dgacAttachPivot($file, $group, 'in_group');

    DynamicGroup::whereKey($group->id)->delete();

    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($file->fresh())->not->toBeNull();

    $pivot = DB::table('cached_content_file_dynamic_groups')->where('cached_content_file_id', $file->id)->first();
    expect($pivot->dynamic_group_id)->toBeNull()
        ->and($pivot->dropped_at)->toBeNull();
});

it('keeps a renamed group\'s adopted file and releases only the old NULL-group row', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    dgacAttachMember($group, $channel, 0);

    $file = CachedContentFile::factory()->completed()->dynamicGroupManaged()->forItem($channel)->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'bytes');
    dgacAttachPivot($file, $group, 'in_group');

    // The rule is renamed: the old group is deleted by stale cleanup and a
    // new group materializes with the new name.
    DynamicGroup::whereKey($group->id)->delete();

    $config = $playlist->fresh()->dynamic_groups_config;
    $config[0]['name'] = 'Renamed Group';
    $playlist->update(['dynamic_groups_config' => $config]);

    $renamed = DynamicGroup::factory()->for($playlist)->create([
        'user_id' => $playlist->user_id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Renamed Group',
    ]);
    dgacAttachMember($renamed, $channel, 0);

    // The fan-out adopts the old group's file: AlreadyCached + re-attach.
    $counts = app(CachedContentDispatchService::class)->dispatchForDynamicGroup($renamed);

    expect($counts[CacheDispatchResult::AlreadyCached->value])->toBe(1);

    // First sweep: the NULL-group row is stamped but the 24h floor keeps
    // it; the file is kept either way — the renamed group holds it.
    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($file->fresh())->not->toBeNull();

    $pivotGroupIds = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->pluck('dynamic_group_id')
        ->all();
    expect($pivotGroupIds)->toContain($renamed->id)
        ->toContain(null);

    // After the floor elapses, the old NULL-group row is released under its
    // own snapshot while the adopted file survives on the renamed group's row.
    Carbon::setTestNow(now()->addHours(25));
    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($file->fresh())->not->toBeNull();

    $remainingPivots = DB::table('cached_content_file_dynamic_groups')
        ->where('cached_content_file_id', $file->id)
        ->pluck('dynamic_group_id')
        ->all();
    expect($remainingPivots)->toBe([$renamed->id]);
});

it('never deletes a manual file via group retention', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);
    $channel = dgacChannel($playlist, 1);
    dgacAttachMember($group, $channel, 0);

    $file = CachedContentFile::factory()->completed()->forItem($channel)->create(); // manual
    dgacAttachPivot($file, $group, 'in_group');

    DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->delete();

    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($file->fresh())->not->toBeNull();
});

it('never deletes Pending or Downloading files', function () {
    [$playlist, $group] = dgacPlaylistWithGroup('vod', ['cache_enabled' => true]);

    $pendingChannel = dgacChannel($playlist, 1);
    $pending = CachedContentFile::factory()->dynamicGroupManaged()->forItem($pendingChannel)->create();
    dgacAttachPivot($pending, $group, 'in_group');

    $downloadingChannel = dgacChannel($playlist, 2);
    $downloading = CachedContentFile::factory()->downloading()->dynamicGroupManaged()->forItem($downloadingChannel)->create();
    dgacAttachPivot($downloading, $group, 'in_group');

    // Everything left the group's scope.
    DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->delete();

    expect(app(CachedContentRetentionService::class)->releaseDynamicGroupCaches())->toBe(0)
        ->and($pending->fresh())->not->toBeNull()
        ->and($downloading->fresh())->not->toBeNull();
});
