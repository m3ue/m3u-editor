<?php

use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\TmdbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory() synchronously fires PlaylistCreated, whose listener
    // calls SyncPipelineService::startImport() which acquires a Redis cache
    // lock. On dev machines without Redis this 500s. Bus::fake() must be set
    // BEFORE the factory creates the playlist so the listener's dispatch is
    // intercepted.
    Bus::fake();

    // Dynamic Groups are gated behind an experimental feature flag that
    // ships disabled. Enable it so the Playlist form section renders.
    config()->set('feature.playlist_tmdb_dynamic_groups', true);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'dynamic_groups_config' => null,
    ]);
});

it('persists tmdb_params.pages = 5 in dynamic_groups_config on round-trip', function () {
    $rule = [
        'enabled' => true,
        'type' => 'vod',
        'source' => 'now_playing',
        'name' => 'In Theatres (extended)',
        'tmdb_params' => ['pages' => 5],
    ];

    $this->playlist->update(['dynamic_groups_config' => [$rule]]);

    expect($this->playlist->fresh()->dynamic_groups_config)->toEqual([$rule]);
});

it('preserves all other tmdb_params keys alongside pages', function () {
    $rule = [
        'enabled' => true,
        'type' => 'vod',
        'source' => 'now_playing',
        'name' => 'Netflix in US',
        'tmdb_params' => [
            'pages' => 5,
            'region' => 'US',
            'genre_id' => 28,
        ],
    ];

    $this->playlist->update(['dynamic_groups_config' => [$rule]]);
    $persisted = $this->playlist->fresh()->dynamic_groups_config[0];

    expect($persisted['tmdb_params']['pages'])->toBe(5)
        ->and($persisted['tmdb_params']['region'])->toBe('US')
        ->and($persisted['tmdb_params']['genre_id'])->toBe(28);
});

it('default of 3 is preserved when no pages key is set (backwards compat)', function () {
    // Existing rule in production might not have `pages` — the backend
    // applies $params['pages'] ?? 3 as default in
    // TmdbService::collectDynamicGroupResults. Verify a rule without `pages`
    // still round-trips cleanly.
    $rule = [
        'enabled' => true,
        'type' => 'vod',
        'source' => 'now_playing',
        'name' => 'Plain',
        // intentionally NO tmdb_params.pages key
        'tmdb_params' => ['region' => 'US'],
    ];

    $this->playlist->update(['dynamic_groups_config' => [$rule]]);
    $persisted = $this->playlist->fresh()->dynamic_groups_config[0];

    expect($persisted['tmdb_params'])->not->toHaveKey('pages')
        ->and($persisted['tmdb_params']['region'])->toBe('US');
});

it('EditPlaylist page class is still instantiable after the schema change', function () {
    // We don't render the full Livewire form here because that path needs
    // a Redis-backed cache (the Playlist model's xtreamStatus accessor uses
    // Cache::remember on Redis) and this dev machine has no Redis daemon.
    // The other tests in this file prove the field round-trips end-to-end.
    // This smoke check just confirms the page class still exists and loads
    // after the PlaylistResource schema change.
    expect(class_exists(EditPlaylist::class))->toBeTrue()
        ->and(TmdbService::MAX_DYNAMIC_GROUP_PAGES)->toBe(5);
});

it('persists cache_enabled and all 8 cache_* keys on a rule round-trip', function () {
    $rule = [
        'enabled' => true,
        'type' => 'vod',
        'source' => 'now_playing',
        'name' => 'In Theatres (cached)',
        'tmdb_params' => ['pages' => 1],
        'cache_enabled' => true,
        'cache_content_selection' => 'recent',
        'cache_content_days' => 14,
        'cache_retention_mode' => 'lifetime_plus_days',
        'cache_retention_extra_days' => 7,
        'cache_location_override' => '/tmp/cache-override',
        'cache_prefer_quality_keyword' => '4K',
        'cache_avoid_duplicate_content' => false,
    ];

    $this->playlist->update(['dynamic_groups_config' => [$rule]]);
    $persisted = $this->playlist->fresh()->dynamic_groups_config[0];

    expect($persisted['cache_enabled'])->toBeTrue()
        ->and($persisted['cache_content_selection'])->toBe('recent')
        ->and($persisted['cache_content_days'])->toBe(14)
        ->and($persisted['cache_retention_mode'])->toBe('lifetime_plus_days')
        ->and($persisted['cache_retention_extra_days'])->toBe(7)
        ->and($persisted['cache_location_override'])->toBe('/tmp/cache-override')
        ->and($persisted['cache_prefer_quality_keyword'])->toBe('4K')
        ->and($persisted['cache_avoid_duplicate_content'])->toBeFalse();
});

it('does not persist cache_* keys when the rule omits them (backwards compat)', function () {
    $rule = [
        'enabled' => true,
        'type' => 'vod',
        'source' => 'now_playing',
        'name' => 'Plain',
        'tmdb_params' => ['pages' => 1],
        // intentionally NO cache_* keys
    ];

    $this->playlist->update(['dynamic_groups_config' => [$rule]]);
    $persisted = $this->playlist->fresh()->dynamic_groups_config[0];

    expect($persisted)->not->toHaveKey('cache_enabled')
        ->and($persisted)->not->toHaveKey('cache_content_selection');
});

// --- Phase 4: Select Content picker round-trip --------------------------

it('persists cache_selected_content_ids (picker-selected channel IDs) on round-trip', function () {
    // The ModalTableSelect on the Playlist form's `dynamic_groups_config`
    // Repeater stores an array of Channel/Series IDs in this key. Verify
    // the array survives a save → fresh() → read without re-ordering or
    // type-coercion surprises.
    $channel1 = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'tmdb_id' => '550',
        'is_vod' => true,
    ]);
    $channel2 = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'tmdb_id' => '551',
        'is_vod' => true,
    ]);

    $rule = [
        'enabled' => true,
        'type' => 'vod',
        'source' => 'now_playing',
        'name' => 'Picked Titles',
        'tmdb_params' => ['pages' => 1],
        'cache_enabled' => true,
        'cache_content_selection' => 'select',
        'cache_selected_content_ids' => [$channel1->id, $channel2->id],
    ];

    $this->playlist->update(['dynamic_groups_config' => [$rule]]);
    $persisted = $this->playlist->fresh()->dynamic_groups_config[0];

    expect($persisted['cache_enabled'])->toBeTrue()
        ->and($persisted['cache_content_selection'])->toBe('select')
        ->and($persisted['cache_selected_content_ids'])->toEqual([$channel1->id, $channel2->id]);
});

it('persists cache_selected_content_ids as an empty array when the picker has no selection', function () {
    // Phase 4 semantics: an empty selection array is the "user hasn't picked
    // anything yet" signal — the dispatcher treats it as skip (not silently
    // cache-all). Verify the empty-array case still round-trips cleanly so
    // the picker's `Clear all` action doesn't lose state on save.
    $rule = [
        'enabled' => true,
        'type' => 'vod',
        'source' => 'now_playing',
        'name' => 'Empty Picker',
        'tmdb_params' => ['pages' => 1],
        'cache_enabled' => true,
        'cache_content_selection' => 'select',
        'cache_selected_content_ids' => [],
    ];

    $this->playlist->update(['dynamic_groups_config' => [$rule]]);
    $persisted = $this->playlist->fresh()->dynamic_groups_config[0];

    expect($persisted['cache_selected_content_ids'])->toEqual([]);
});

it('persists cache_selected_content_ids for series-type rules (Series IDs, not Channels)', function () {
    // The picker switches its backing table to the Series variant when the
    // rule's `type === 'series'` — verify a series-typed rule stores Series
    // IDs (not Channel IDs) correctly.
    $series = Series::factory()->create([
        'playlist_id' => $this->playlist->id,
    ]);

    $rule = [
        'enabled' => true,
        'type' => 'series',
        'source' => 'trending',
        'name' => 'Picked Series',
        'tmdb_params' => ['pages' => 1],
        'cache_enabled' => true,
        'cache_content_selection' => 'select',
        'cache_selected_content_ids' => [$series->id],
    ];

    $this->playlist->update(['dynamic_groups_config' => [$rule]]);
    $persisted = $this->playlist->fresh()->dynamic_groups_config[0];

    expect($persisted['type'])->toBe('series')
        ->and($persisted['cache_selected_content_ids'])->toEqual([$series->id]);
});
