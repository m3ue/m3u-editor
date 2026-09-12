<?php

use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    app(GeneralSettings::class)->refresh();
});

// NOTE: Playlist::factory()->create() fires PlaylistCreated → SyncPipelineService →
// dispatch(ProcessM3uImport). Bus::fake() captures it. We assert against the
// specific job we care about (DownloadCachedContentFile) rather than asserting
// nothing was dispatched.

it('no-ops when enable_dynamic_group_cache is false', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = false;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->artisan('app:cache-dynamic-group-content')->assertSuccessful();

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('no-ops when dynamic_group_cache_lazy_load is true', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = true;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->artisan('app:cache-dynamic-group-content')->assertSuccessful();

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('no-ops when the cron schedule is not due', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = false;
    // Feb 31 — never valid, so isDue() is always false
    app(GeneralSettings::class)->dynamic_group_cache_schedule = '0 0 31 2 *';
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->artisan('app:cache-dynamic-group-content')->assertSuccessful();

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('dispatches one job per eligible VOD item when cron is due', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = false;
    app(GeneralSettings::class)->dynamic_group_cache_schedule = '* * * * *';
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Top Movies',
                'tmdb_params' => ['pages' => 1],
                'cache_enabled' => true,
                'cache_content_selection' => 'all',
                'cache_content_days' => 30,
                'cache_retention_mode' => 'match_group_lifetime',
                'cache_retention_extra_days' => 0,
                'cache_prefer_quality_keyword' => '1080p',
            ],
        ],
    ]);

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Top Movies',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'tmdb_id' => '550',
        'url' => 'http://example.com/movie.mp4',
        'is_vod' => true,
    ]);
    $group->channels()->attach($channel->id);

    $this->artisan('app:cache-dynamic-group-content')->assertSuccessful();

    Bus::assertDispatched(DownloadCachedContentFile::class, 1);
});

it('skips content with cache_content_selection = select when no IDs are picked (empty picker)', function () {
    // Phase 4: 'select' is no longer a global no-op — empty selection means
    // "user hasn't picked anything yet", so we skip rather than silently
    // caching everything (which would defeat the point of switching to 'select').
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = false;
    app(GeneralSettings::class)->dynamic_group_cache_schedule = '* * * * *';
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Picked Titles',
                'cache_enabled' => true,
                'cache_content_selection' => 'select',
                'cache_selected_content_ids' => [],
            ],
        ],
    ]);

    $this->artisan('app:cache-dynamic-group-content')->assertSuccessful();

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('filters to selected IDs when cache_content_selection = select (Phase 4 picker)', function () {
    // Phase 4: 'select' now actually filters to the picked membership subset
    // rather than no-op'ing the whole rule.
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = false;
    app(GeneralSettings::class)->dynamic_group_cache_schedule = '* * * * *';
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Picked Titles',
    ]);

    $pickedChannel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'tmdb_id' => '550',
        'url' => 'http://example.com/picked.mp4',
        'is_vod' => true,
    ]);
    $skippedChannel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'tmdb_id' => '551',
        'url' => 'http://example.com/skipped.mp4',
        'is_vod' => true,
    ]);
    $group->channels()->attach([$pickedChannel->id, $skippedChannel->id]);

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Picked Titles',
                'cache_enabled' => true,
                'cache_content_selection' => 'select',
                'cache_selected_content_ids' => [$pickedChannel->id],
            ],
        ],
    ]);

    $this->artisan('app:cache-dynamic-group-content')->assertSuccessful();

    Bus::assertDispatched(DownloadCachedContentFile::class, 1);
    Bus::assertDispatched(DownloadCachedContentFile::class, function (DownloadCachedContentFile $job) use ($pickedChannel, $skippedChannel) {
        return $job->tmdbId === (string) $pickedChannel->tmdb_id
            && $job->tmdbId !== (string) $skippedChannel->tmdb_id;
    });
});

it('skips VOD items whose release_date is older than cache_content_days when selection is recent', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = false;
    app(GeneralSettings::class)->dynamic_group_cache_schedule = '* * * * *';
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Recent Only',
                'cache_enabled' => true,
                'cache_content_selection' => 'recent',
                'cache_content_days' => 30,
            ],
        ],
    ]);

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Recent Only',
    ]);

    // release_date lives in the info JSON column on Channel (no top-level column).
    $oldChannel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'tmdb_id' => '111',
        'url' => 'http://example.com/old.mp4',
        'is_vod' => true,
        'info' => ['release_date' => now()->subDays(60)->toDateString()],
    ]);
    $recentChannel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'tmdb_id' => '222',
        'url' => 'http://example.com/recent.mp4',
        'is_vod' => true,
        'info' => ['release_date' => now()->subDays(5)->toDateString()],
    ]);
    $group->channels()->attach($oldChannel->id);
    $group->channels()->attach($recentChannel->id);

    $this->artisan('app:cache-dynamic-group-content')->assertSuccessful();

    Bus::assertDispatched(DownloadCachedContentFile::class, 1); // only the recent one
});

it('skips already-completed fingerprints (cross-playlist dedup)', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = false;
    app(GeneralSettings::class)->dynamic_group_cache_schedule = '* * * * *';
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Anything',
                'cache_enabled' => true,
                'cache_content_selection' => 'all',
            ],
        ],
    ]);

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Anything',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'tmdb_id' => '550',
        'url' => 'http://example.com/movie.mp4',
        'is_vod' => true,
    ]);
    $group->channels()->attach($channel->id);

    // Seed a Completed row with the same parts → auto-derived fingerprint matches
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
    ]);

    $this->artisan('app:cache-dynamic-group-content')->assertSuccessful();

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('skips Failed fingerprints within the retry cooldown window', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = false;
    app(GeneralSettings::class)->dynamic_group_cache_schedule = '* * * * *';
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Anything',
                'cache_enabled' => true,
                'cache_content_selection' => 'all',
            ],
        ],
    ]);

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Anything',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'tmdb_id' => '550',
        'url' => 'http://example.com/movie.mp4',
        'is_vod' => true,
    ]);
    $group->channels()->attach($channel->id);

    // Failed 5 minutes ago — well within the 360-minute default cooldown
    CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'last_failed_at' => now()->subMinutes(5),
        'failure_count' => 1,
    ]);

    $this->artisan('app:cache-dynamic-group-content')->assertSuccessful();

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('dispatches when Failed fingerprint is past the retry cooldown window', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = false;
    app(GeneralSettings::class)->dynamic_group_cache_schedule = '* * * * *';
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Anything',
                'cache_enabled' => true,
                'cache_content_selection' => 'all',
            ],
        ],
    ]);

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Anything',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'tmdb_id' => '550',
        'url' => 'http://example.com/movie.mp4',
        'is_vod' => true,
    ]);
    $group->channels()->attach($channel->id);

    // Failed 400 minutes ago — past the 360-minute default cooldown
    CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'last_failed_at' => now()->subMinutes(400),
        'failure_count' => 1,
    ]);

    $this->artisan('app:cache-dynamic-group-content')->assertSuccessful();

    Bus::assertDispatched(DownloadCachedContentFile::class, 1);
});

it('no-ops when enable_proxy is false on the playlist', function () {
    $this->playlist->update(['enable_proxy' => false]);

    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = false;
    app(GeneralSettings::class)->dynamic_group_cache_schedule = '* * * * *';
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->artisan('app:cache-dynamic-group-content')->assertSuccessful();

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});
