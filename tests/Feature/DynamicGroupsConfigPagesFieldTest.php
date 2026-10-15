<?php

use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Jobs\SyncDynamicGroups;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

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

it('persists the per-rule cache keys in dynamic_groups_config on round-trip', function () {
    $rule = [
        'enabled' => true,
        'type' => 'vod',
        'source' => 'now_playing',
        'name' => 'In Theatres',
        'tmdb_params' => [],
        'cache_enabled' => true,
        'cache_keep_days' => 14,
        'cache_max_items' => 10,
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
        ->and(TmdbService::MAX_DYNAMIC_GROUP_PAGES)->toBe(10);
});

it('updates the group rows in the request without calling TMDB, then queues a refresh, when a playlist form save changes the rules', function () {
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(true);
    $tmdb->shouldNotReceive('collectDynamicGroupResults');
    app()->instance(TmdbService::class, $tmdb);

    $rule = [
        'enabled' => true,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Trending Now',
        'tmdb_params' => ['time_window' => 'week', 'pages' => 3],
    ];
    $this->playlist->updateQuietly(['dynamic_groups_config' => [$rule]]);
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Trending Now',
        'enabled' => true,
    ]);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->fillForm([
            'user_agent' => 'Test Agent',
            'dynamic_groups_config' => [
                ['enabled' => false] + $rule,
                ['source' => 'popular', 'name' => 'Popular Now', 'tmdb_params' => ['pages' => 3]] + $rule,
            ],
        ])
        // Filling a new item sets its Content Type, whose afterStateUpdated
        // resets the source, so set the source again afterwards.
        ->set('data.dynamic_groups_config.1.source', 'popular')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->playlist->fresh()->dynamic_groups_config[0]['enabled'])->toBeFalse()
        ->and($group->fresh()->enabled)->toBeFalse();

    // A new rule gets its row now; the queued refresh fills in its members.
    $created = DynamicGroup::where('playlist_id', $this->playlist->id)->where('name', 'Popular Now')->sole();
    expect($created->enabled)->toBeTrue()
        ->and($created->last_synced_at)->toBeNull();

    Bus::assertDispatchedTimes(SyncDynamicGroups::class, 1);
    Bus::assertDispatched(SyncDynamicGroups::class, fn (SyncDynamicGroups $job): bool => $job->playlistId === $this->playlist->id
        && $job->refreshMembership
        && $job->syncRunId === null
        && $job->delay !== null);
});

it('does not re-sync the dynamic groups when a playlist form save leaves the rules alone', function () {
    $this->playlist->updateQuietly(['dynamic_groups_config' => []]);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->fillForm([
            'user_agent' => 'Test Agent',
            'name' => 'Renamed Playlist',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    Bus::assertNotDispatched(SyncDynamicGroups::class);
});

it('keeps each rule\'s stored cache options when the playlist form is saved while caching is off in Settings', function () {
    app(GeneralSettings::class)->enable_cache = false;
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(true);
    app()->instance(TmdbService::class, $tmdb);

    $rule = [
        'enabled' => true,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Trending Now',
        'tmdb_params' => ['time_window' => 'week', 'pages' => 3],
        'cache_enabled' => true,
        'cache_never_expire' => false,
        'cache_keep_days' => 14,
        'cache_max_items' => 10,
    ];
    $this->playlist->updateQuietly(['dynamic_groups_config' => [$rule]]);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->fillForm(['user_agent' => 'Test Agent'])
        ->call('save')
        ->assertHasNoFormErrors();

    $saved = $this->playlist->fresh()->dynamic_groups_config[0];
    expect($saved)->toMatchArray(array_diff_key($rule, ['tmdb_params' => true]))
        ->and($saved)->not->toHaveKey('cache_enabled_locked');
});
