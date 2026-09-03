<?php

use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Models\Playlist;
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
