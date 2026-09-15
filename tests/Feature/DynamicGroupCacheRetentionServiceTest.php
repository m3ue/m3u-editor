<?php

use App\Enums\CachedContentFileStatus;
use App\Jobs\SyncDynamicGroups;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use App\Services\DynamicGroupCacheRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);

    $this->service = app(DynamicGroupCacheRetentionService::class);
});

// Note: factory->completed() sets file_path/disk columns but doesn't actually write
// a file to storage — so DB-level assertions (row exists, pivot count) are the
// reliable signal here. The Storage::delete call in the service is a no-op when
// the file doesn't exist, so production behavior is unaffected.

it('detaches the pivot row when match_group_lifetime content falls out of membership', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Top Movies',
                'cache_enabled' => true,
                'cache_content_selection' => 'all',
                'cache_retention_mode' => 'match_group_lifetime',
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

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'disk' => 'local',
        'file_path' => 'cache/movie-550.mp4',
    ]);
    $file->dynamicGroups()->attach($group->id);

    $this->service->runAll();

    // Row hard-deleted (no referencing groups after retention ran)
    expect($file->fresh())->toBeNull();
});

it('does not delete file when any group uses never_expire mode', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Forever Group',
                'cache_enabled' => true,
                'cache_content_selection' => 'all',
                'cache_retention_mode' => 'never_expire',
            ],
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Disposable Group',
                'cache_enabled' => true,
                'cache_content_selection' => 'all',
                'cache_retention_mode' => 'match_group_lifetime',
            ],
        ],
    ]);

    $foreverGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Forever Group',
    ]);
    $disposableGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Disposable Group',
    ]);

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'disk' => 'local',
        'file_path' => 'cache/movie-550.mp4',
    ]);
    $file->dynamicGroups()->attach([$foreverGroup->id, $disposableGroup->id]);

    $this->service->runAll();

    // File survives — never_expire rule pinned it
    expect($file->fresh())->not->toBeNull();

    // Disposable group should be detached, forever group stays
    expect($foreverGroup->fresh()->cachedContentFiles)->toHaveCount(1)
        ->and($disposableGroup->fresh()->cachedContentFiles)->toHaveCount(0);
});

it('preserves files when a never_expire rule is disabled (SyncDynamicGroups pins the file before deleting the orphaned group)', function () {
    // Regression for PR #1500 Bug 3: SyncDynamicGroups hard-deletes stale
    // DynamicGroup rows (rules that were disabled or renamed), the pivot's
    // cascadeOnDelete removes the group's references in the same
    // transaction, and the next retention pass hard-deletes the file even
    // though the rule that was just disabled said never_expire. The fix
    // stamps `never_expire=true` on referenced CachedContentFile rows
    // before the DynamicGroup::delete() fires; retention then short-
    // circuits on that flag regardless of pivot state.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // Step 1: a never_expire rule with a materialized DynamicGroup and a
    // Completed cached file pinned to it.
    $this->playlist->update([
        'dynamic_groups_config' => [[
            'enabled' => true,
            'type' => 'vod',
            'source' => 'trending',
            'name' => 'Forever Group',
            'cache_enabled' => true,
            'cache_retention_mode' => 'never_expire',
        ]],
    ]);

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Forever Group',
    ]);

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'disk' => 'local',
        'file_path' => 'cache/movie-550.mp4',
    ]);
    $file->dynamicGroups()->attach($group->id);

    // Step 2: simulate the user disabling the rule via the Edit form and
    // re-syncing — the playlist rule flips to `enabled: false`, then
    // SyncDynamicGroups runs. We don't go through the form here, we just
    // mutate the config in-place + invoke the job directly so this test
    // owns its exact precondition without depending on Filament form
    // semantics.
    $this->playlist->update([
        'dynamic_groups_config' => [[
            'enabled' => false,
            'type' => 'vod',
            'source' => 'trending',
            'name' => 'Forever Group',
            'cache_enabled' => true,
            'cache_retention_mode' => 'never_expire',
        ]],
    ]);

    (new SyncDynamicGroups($this->playlist->id))->handle();

    // The DynamicGroup row is gone (cascaded via SyncDynamicGroups
    // stale-row cleanup), but the file should still be alive — the
    // never_expire flag was stamped before the delete.
    expect($group->fresh())->toBeNull()
        ->and($file->fresh())->not->toBeNull()
        ->and($file->fresh()->never_expire)->toBeTrue();

    // Step 3: retention must NOT delete the file.
    $this->service->runAll();

    expect($file->fresh())->not->toBeNull();
});

it('does not pin files for stale groups whose rule had a non-never_expire retention mode', function () {
    // Counter-test to the previous one: disabling a match_group_lifetime
    // rule should still allow retention to delete the file once the
    // pivot rows are gone. Pinning everything would leak orphaned files
    // forever.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $this->playlist->update([
        'dynamic_groups_config' => [[
            'enabled' => true,
            'type' => 'vod',
            'source' => 'trending',
            'name' => 'Disposable Group',
            'cache_enabled' => true,
            'cache_retention_mode' => 'match_group_lifetime',
        ]],
    ]);

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Disposable Group',
    ]);

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'disk' => 'local',
        'file_path' => 'cache/movie-550.mp4',
    ]);
    $file->dynamicGroups()->attach($group->id);

    // Disable the rule and re-sync.
    $this->playlist->update([
        'dynamic_groups_config' => [[
            'enabled' => false,
            'type' => 'vod',
            'source' => 'trending',
            'name' => 'Disposable Group',
            'cache_enabled' => true,
            'cache_retention_mode' => 'match_group_lifetime',
        ]],
    ]);

    (new SyncDynamicGroups($this->playlist->id))->handle();

    expect($file->fresh())->not->toBeNull()
        ->and($file->fresh()->never_expire)->toBeFalse();

    // Retention hard-deletes the now-orphan file (same as the
    // pre-fix behavior for non-never_expire groups).
    $this->service->runAll();
    expect($file->fresh())->toBeNull();
});

it('keeps files that are still in the group\'s live membership', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Top Movies',
                'cache_enabled' => true,
                'cache_content_selection' => 'all',
                'cache_retention_mode' => 'match_group_lifetime',
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

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'disk' => 'local',
        'file_path' => 'cache/movie-550.mp4',
    ]);
    $file->dynamicGroups()->attach($group->id);

    $this->service->runAll();

    expect($file->fresh())->not->toBeNull()
        ->and($file->dynamicGroups)->toHaveCount(1);
});

// --- PR #1500 Bug 4: tvdb_id mismatches between live membership and cache ---

it('keeps a cached file when the live channel has a matching tvdb_id (VOD)', function () {
    // Regression for PR #1500 Bug 4: liveFingerprintsForGroup() built
    // fingerprints without tvdb_id, but CachedContentFile::fingerprintFor()
    // includes tvdb_id in the format `content_type:tmdb_id:tvdb_id:...:quality`.
    // A row cached with a non-null tvdb_id never matched live membership
    // and could be hard-deleted while still wanted by the group.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $this->playlist->update([
        'dynamic_groups_config' => [[
            'enabled' => true,
            'type' => 'vod',
            'source' => 'trending',
            'name' => 'Top Movies',
            'cache_enabled' => true,
            'cache_retention_mode' => 'match_group_lifetime',
        ]],
    ]);

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Top Movies',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'tmdb_id' => '550',
        'tvdb_id' => 81351,
        'url' => 'http://example.com/movie.mp4',
        'is_vod' => true,
    ]);
    $group->channels()->attach($channel->id);

    // Cached row uses both tmdb_id AND tvdb_id — matches the channel.
    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'tvdb_id' => '81351',
        'quality' => null,
        'disk' => 'local',
        'file_path' => 'cache/movie:550:81351:::.mp4',
    ]);
    $file->dynamicGroups()->attach($group->id);

    $this->service->runAll();

    expect($file->fresh())->not->toBeNull()
        ->and($file->fresh()->dynamicGroups)->toHaveCount(1);
});

it('does NOT keep a cached file when tvdb_id differs between live channel and cache (VOD)', function () {
    // Counter-test to the previous one: the fix produces a fingerprint
    // that includes tvdb_id, so a cache row with a DIFFERENT tvdb_id than
    // the live channel must be treated as out-of-membership even if
    // tmdb_id matches. (Different tvdb_id typically means a different
    // regional cut — content identity doesn't match and the cached file
    // is no longer what the group wants.)
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $this->playlist->update([
        'dynamic_groups_config' => [[
            'enabled' => true,
            'type' => 'vod',
            'source' => 'trending',
            'name' => 'Top Movies',
            'cache_enabled' => true,
            'cache_retention_mode' => 'match_group_lifetime',
        ]],
    ]);

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Top Movies',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'tmdb_id' => '550',
        'tvdb_id' => 99999, // different from cached row
        'url' => 'http://example.com/movie.mp4',
        'is_vod' => true,
    ]);
    $group->channels()->attach($channel->id);

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'tvdb_id' => '81351',
        'quality' => null,
        'disk' => 'local',
        'file_path' => 'cache/movie:550:81351:::.mp4',
    ]);
    $file->dynamicGroups()->attach($group->id);

    $this->service->runAll();

    // File is detached and then hard-deleted because no live membership
    // shares the same (tmdb_id, tvdb_id) fingerprint.
    expect($file->fresh())->toBeNull();
});

it('does nothing when there are no CachedContentFiles', function () {
    $this->service->runAll();

    expect(CachedContentFile::count())->toBe(0);
});

it('cleanupStorageOrphans() returns an empty array when cache/ has no files', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    expect($this->service->cleanupStorageOrphans())->toBe([]);
});

it('cleanupStorageOrphans() reports unreferenced files in cache/ as orphans', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // Three files in Storage, two of which are referenced by DB rows.
    Storage::disk('local')->put('cache/movie:1::::.mp4', 'referenced-1');
    Storage::disk('local')->put('cache/movie:2::::.mp4', 'orphan');
    Storage::disk('local')->put('cache/movie:3::::.mp4', 'referenced-2');
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '1',
        'file_path' => 'cache/movie:1::::.mp4',
    ]);
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '3',
        'file_path' => 'cache/movie:3::::.mp4',
    ]);

    $orphans = $this->service->cleanupStorageOrphans();

    expect($orphans)->toBe(['cache/movie:2::::.mp4']);
    // delete=true by default → orphan file is gone, referenced files remain
    expect(Storage::disk('local')->exists('cache/movie:2::::.mp4'))->toBeFalse()
        ->and(Storage::disk('local')->exists('cache/movie:1::::.mp4'))->toBeTrue()
        ->and(Storage::disk('local')->exists('cache/movie:3::::.mp4'))->toBeTrue();
});

it('cleanupStorageOrphans(delete: false) reports orphans without removing them', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Storage::disk('local')->put('cache/orphan.mp4', 'orphan-data');

    $orphans = $this->service->cleanupStorageOrphans(delete: false);

    expect($orphans)->toBe(['cache/orphan.mp4'])
        ->and(Storage::disk('local')->exists('cache/orphan.mp4'))->toBeTrue();
});

it('hardDelete() removes the Storage file even for non-Completed rows that have file_path set', function () {
    // Defense-in-depth: previously hardDelete() gated Storage::delete on
    // hasFilePath() (status=Completed && file_path set). If any future code
    // path leaves file_path set on a Failed/Downloading row, we still want
    // the file cleaned up. Reflectively call the private hardDelete to
    // exercise the full path (Storage delete + row delete) without needing
    // to construct a retention scenario that triggers it organically.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Storage::disk('local')->put('cache/movie:1::::.mp4', 'data');
    $row = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie', 'tmdb_id' => '1',
        'file_path' => 'cache/movie:1::::.mp4',
    ]);
    expect($row->status)->not->toBe(CachedContentFileStatus::Completed)
        ->and(Storage::disk('local')->exists($row->file_path))->toBeTrue();

    $reflection = new ReflectionMethod($this->service, 'hardDelete');
    $reflection->setAccessible(true);
    $reflection->invoke($this->service, $row);

    // Both halves: Storage file gone + row deleted.
    expect(Storage::disk('local')->exists('cache/movie:1::::.mp4'))->toBeFalse()
        ->and(CachedContentFile::find($row->id))->toBeNull();
});
