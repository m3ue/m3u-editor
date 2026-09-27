<?php

use App\Events\PlaylistUpdated;
use App\Filament\Resources\DynamicGroups\Pages\ViewDynamicGroup;
use App\Filament\Resources\SeriesDynamicGroups\Pages\ListSeriesDynamicGroups;
use App\Filament\Resources\VodDynamicGroups\Pages\ListVodDynamicGroups;
use App\Jobs\SyncDynamicGroups;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Dynamic Groups are gated behind an experimental feature flag that
    // ships disabled. Enable it so the listing pages render under test.
    config()->set('feature.playlist_tmdb_dynamic_groups', true);

    Bus::fake();
    Http::preventStrayRequests();

    // Also gated behind a configured TMDB integration for the per-type
    // listing resources. The View page doesn't gate on this, but we share
    // the mock setup across the whole test file for simplicity.
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(true);
    $tmdb->shouldReceive('collectDynamicGroupResults')
        ->andReturn([['tmdb_id' => '100']]);
    app()->instance(TmdbService::class, $tmdb);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

// ──────────────────────────────────────────────────────────────────────────────
// Helper: seed a config with three rules and return the matching DynamicGroup
// rows. Layout chosen so the "order preserved" assertion below can prove we
// only stripped the target rule, not reordered the survivors.
// ──────────────────────────────────────────────────────────────────────────────

function seedConfigAndRowsForIssue1550Test($t): array
{
    // Seed a config with three rules, intentionally in a non-alphabetical
    // order so the "preserved in original order" assertion below can detect
    // a reindex/reorder regression:
    //   [0] 'Keep First'  (vod / trending)
    //   [1] 'Target'      (vod / trending) ← the row we'll delete
    //   [2] '  Target  '  (vod / trending, name with surrounding whitespace)
    //   [3] 'Target'      (series / trending, same name, different type - must survive)
    //   [4] 'Target'      (vod / popular,   same name, different source - must survive)
    //   [5] 'Keep Last'   (vod / trending)
    $rules = [
        ['enabled' => true, 'type' => 'vod', 'source' => 'trending', 'name' => 'Keep First', 'tmdb_params' => []],
        ['enabled' => true, 'type' => 'vod', 'source' => 'trending', 'name' => 'Target', 'tmdb_params' => []],
        ['enabled' => true, 'type' => 'vod', 'source' => 'trending', 'name' => '  Target  ', 'tmdb_params' => []],
        ['enabled' => true, 'type' => 'series', 'source' => 'trending', 'name' => 'Target', 'tmdb_params' => []],
        ['enabled' => true, 'type' => 'vod', 'source' => 'popular', 'name' => 'Target', 'tmdb_params' => []],
        ['enabled' => true, 'type' => 'vod', 'source' => 'trending', 'name' => 'Keep Last', 'tmdb_params' => []],
    ];

    $t->playlist->updateQuietly(['dynamic_groups_config' => $rules]);

    // Materialize the target row - the one whose triple (vod, trending,
    // trim('Target')) matches rule [1]. Also materialize a couple of
    // survivors so the listing pages have something to render alongside.
    $target = DynamicGroup::create([
        'playlist_id' => $t->playlist->id,
        'user_id' => $t->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Target',
    ]);
    DynamicGroup::create([
        'playlist_id' => $t->playlist->id,
        'user_id' => $t->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Keep First',
    ]);
    DynamicGroup::create([
        'playlist_id' => $t->playlist->id,
        'user_id' => $t->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Keep Last',
    ]);

    return ['target' => $target, 'rules' => $rules];
}

// ──────────────────────────────────────────────────────────────────────────────
// Issue #1550: the matching rule must be removed from dynamic_groups_config
// ──────────────────────────────────────────────────────────────────────────────

it('removes the matching rule from dynamic_groups_config when deleted via the VOD listing DeleteAction', function () {
    ['target' => $target, 'rules' => $rules] = seedConfigAndRowsForIssue1550Test($this);

    Livewire::test(ListVodDynamicGroups::class)
        ->callTableAction('delete', $target);

    expect(DynamicGroup::find($target->id))->toBeNull();

    $config = $this->playlist->fresh()->dynamic_groups_config;
    $names = array_column($config, 'name');

    // The rule whose triple (vod, trending, Target) matches the deleted
    // row is gone - and so is the whitespace variant, because the match
    // uses trim() on both sides (mirrors SyncDynamicGroups::runSync()).
    expect(array_filter($config, fn (array $r): bool => trim((string) $r['name']) === 'Target' && $r['type'] === 'vod' && $r['source'] === 'trending'))->toBeEmpty()
        ->and($names)->not->toContain('  Target  ');

    // Survivors remain in their original order (no reindex/reorder
    // regression) and the same-name but different-type / different-source
    // rules are preserved - the match is by full triple.
    expect($names)->toContain('Keep First')
        ->and($names)->toContain('Keep Last');

    expect(array_filter($config, fn (array $r): bool => $r['name'] === 'Target' && $r['type'] === 'series'))->toHaveCount(1)
        ->and(array_filter($config, fn (array $r): bool => $r['name'] === 'Target' && $r['source'] === 'popular'))->toHaveCount(1);

    expect(array_map(fn (array $r): string => "{$r['type']}:{$r['source']}:{$r['name']}", $config))->toBe([
        'vod:trending:Keep First',
        'series:trending:Target',
        'vod:popular:Target',
        'vod:trending:Keep Last',
    ]);
});

it('removes the matching rule from dynamic_groups_config when deleted via the Series listing DeleteAction', function () {
    // Build the same shape, but make the target a series-type row so the
    // Series listing DeleteAction is the natural delete surface.
    $rules = [
        ['enabled' => true, 'type' => 'series', 'source' => 'trending', 'name' => 'Keep First', 'tmdb_params' => []],
        ['enabled' => true, 'type' => 'series', 'source' => 'trending', 'name' => 'Target', 'tmdb_params' => []],
        ['enabled' => true, 'type' => 'vod', 'source' => 'trending', 'name' => 'Target', 'tmdb_params' => []],
        ['enabled' => true, 'type' => 'series', 'source' => 'popular', 'name' => 'Target', 'tmdb_params' => []],
        ['enabled' => true, 'type' => 'series', 'source' => 'trending', 'name' => 'Keep Last', 'tmdb_params' => []],
    ];
    $this->playlist->updateQuietly(['dynamic_groups_config' => $rules]);

    $target = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'series',
        'source' => 'trending',
        'name' => 'Target',
    ]);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->callTableAction('delete', $target);

    expect(DynamicGroup::find($target->id))->toBeNull();

    $config = $this->playlist->fresh()->dynamic_groups_config;
    $names = array_column($config, 'name');

    // The rule whose triple (series, trending, Target) matches the deleted
    // row is gone. Same-name but different-type / different-source rules
    // survive.
    expect(array_filter($config, fn (array $r): bool => trim((string) $r['name']) === 'Target' && $r['type'] === 'series' && $r['source'] === 'trending'))->toBeEmpty()
        ->and($names)->toContain('Keep First')
        ->and($names)->toContain('Keep Last');

    expect(array_filter($config, fn (array $r): bool => $r['name'] === 'Target' && $r['type'] === 'vod'))->toHaveCount(1)
        ->and(array_filter($config, fn (array $r): bool => $r['name'] === 'Target' && $r['source'] === 'popular'))->toHaveCount(1);

    expect(array_search('Keep First', $names))->toBeLessThan(array_search('Keep Last', $names));
});

it('removes the matching rule from dynamic_groups_config when deleted via the View page DeleteAction', function () {
    ['target' => $target] = seedConfigAndRowsForIssue1550Test($this);

    Livewire::test(ViewDynamicGroup::class, ['record' => $target->id])
        ->assertOk()
        ->callAction('delete');

    expect(DynamicGroup::find($target->id))->toBeNull();

    $config = $this->playlist->fresh()->dynamic_groups_config;

    // The rule whose triple matches the deleted row is gone.
    expect(array_filter($config, fn (array $r): bool => trim((string) $r['name']) === 'Target' && $r['type'] === 'vod' && $r['source'] === 'trending'))->toBeEmpty()
        ->and(array_column($config, 'name'))->toContain('Keep First')
        ->and(array_column($config, 'name'))->toContain('Keep Last');
});

// ──────────────────────────────────────────────────────────────────────────────
// Resurrection check: the bug is that SyncDynamicGroups recreates deleted
// rows from the config. After the fix, the rule is gone, so a re-sync must
// NOT recreate the deleted row.
// ──────────────────────────────────────────────────────────────────────────────

it('does not recreate the deleted DynamicGroup when SyncDynamicGroups runs after deletion', function () {
    ['target' => $target] = seedConfigAndRowsForIssue1550Test($this);

    // Configure a real TmdbService binding for the sync (the SyncDynamicGroups
    // job uses app(TmdbService::class) directly, not the Mockery mock we
    // installed in beforeEach - install a fully working TmdbService whose
    // HTTP calls are faked here).
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'fake-api-key';
    $settings->tmdb_language = 'en-US';
    $settings->tmdb_rate_limit = 40;
    $settings->tmdb_confidence_threshold = 80;
    app()->instance(GeneralSettings::class, $settings);
    app()->instance(TmdbService::class, new TmdbService($settings));
    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andReturn(1);

    Http::fake([
        'https://api.themoviedb.org/3/*' => Http::response([
            'results' => [
                ['id' => 100, 'title' => 'Hot Movie', 'media_type' => 'movie', 'release_date' => '2024-01-01'],
            ],
            'total_pages' => 1,
        ], 200),
    ]);

    // Delete via the VOD listing DeleteAction (any surface would do - the
    // point is the model hook should fire).
    Livewire::test(ListVodDynamicGroups::class)
        ->callTableAction('delete', $target);

    expect(DynamicGroup::find($target->id))->toBeNull();

    // Now run the sync job synchronously. If the rule is still in the
    // config, SyncDynamicGroups::runSync() will recreate the row. The
    // fix's job is to make sure the rule is gone first.
    (new SyncDynamicGroups(playlistId: $this->playlist->id))->handle();

    // The deleted row must NOT come back. The two 'Keep *' rows are still
    // there because their rules survived. Same-name but different-type /
    // different-source rules also survive (their rows weren't deleted in
    // the first place - only the (vod, trending, Target) row was).
    $remaining = DynamicGroup::where('playlist_id', $this->playlist->id)->get();
    expect($remaining->where('name', 'Target')->where('type', 'vod')->where('source', 'trending'))->toBeEmpty()
        ->and($remaining->pluck('name')->all())->toContain('Keep First')
        ->and($remaining->pluck('name')->all())->toContain('Keep Last')
        ->and($remaining->where('name', 'Target')->where('type', 'series')->count())->toBe(1)
        ->and($remaining->where('name', 'Target')->where('source', 'popular')->count())->toBe(1);
});

// ──────────────────────────────────────────────────────────────────────────────
// PlaylistUpdated must not fire on DynamicGroup delete - that's the whole
// point of saveQuietly(). If it did fire, the user's "updated"
// post-processes (webhooks / scripts) and primary-profile sync would run.
// ──────────────────────────────────────────────────────────────────────────────

it('does not dispatch PlaylistUpdated when a DynamicGroup is deleted', function () {
    ['target' => $target] = seedConfigAndRowsForIssue1550Test($this);

    Event::fake([PlaylistUpdated::class]);

    Livewire::test(ListVodDynamicGroups::class)
        ->callTableAction('delete', $target);

    Event::assertNotDispatched(PlaylistUpdated::class);
});

it('does not dispatch PlaylistUpdated when a DynamicGroup is deleted via the View page', function () {
    ['target' => $target] = seedConfigAndRowsForIssue1550Test($this);

    Event::fake([PlaylistUpdated::class]);

    Livewire::test(ViewDynamicGroup::class, ['record' => $target->id])
        ->assertOk()
        ->callAction('delete');

    Event::assertNotDispatched(PlaylistUpdated::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// Edge cases on the helper itself
// ──────────────────────────────────────────────────────────────────────────────

it('removeRuleFromPlaylistConfig is a no-op when the playlist has no dynamic_groups_config', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Lonely Group',
    ]);

    // Null out the config and persist, then delete the row.
    $this->playlist->updateQuietly(['dynamic_groups_config' => null]);

    $group->delete();

    expect(DynamicGroup::find($group->id))->toBeNull()
        ->and($this->playlist->fresh()->dynamic_groups_config)->toBeNull();
});

it('removeRuleFromPlaylistConfig does not write when no rule matched (no config churn)', function () {
    // Config has a rule with a different name - nothing should be removed,
    // and the playlist's updated_at must not bump.
    $this->playlist->updateQuietly([
        'dynamic_groups_config' => [
            ['enabled' => true, 'type' => 'vod', 'source' => 'trending', 'name' => 'Unrelated', 'tmdb_params' => []],
        ],
    ]);
    $beforeUpdatedAt = $this->playlist->fresh()->updated_at;

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Target',
    ]);

    $this->travel(5)->minutes();

    $group->delete();

    $playlist = $this->playlist->fresh();
    expect($playlist->dynamic_groups_config)->toHaveCount(1)
        ->and($playlist->dynamic_groups_config[0]['name'])->toBe('Unrelated')
        ->and($playlist->updated_at->eq($beforeUpdatedAt))->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// SyncDynamicGroups' stale-row cleanup must never strip rules. Only
// user-initiated (model-level) deletes should touch dynamic_groups_config.
// ──────────────────────────────────────────────────────────────────────────────

it('keeps a disabled rule in dynamic_groups_config when the sync drops its stale row', function () {
    $rules = [
        ['enabled' => true, 'type' => 'vod', 'source' => 'trending', 'name' => 'Active', 'tmdb_params' => []],
        ['enabled' => false, 'type' => 'vod', 'source' => 'trending', 'name' => 'Paused', 'tmdb_params' => []],
    ];
    $this->playlist->updateQuietly(['dynamic_groups_config' => $rules]);

    foreach (['Active', 'Paused'] as $name) {
        DynamicGroup::create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'type' => 'vod',
            'source' => 'trending',
            'name' => $name,
        ]);
    }

    (new SyncDynamicGroups(playlistId: $this->playlist->id))->handle();

    expect(DynamicGroup::where('playlist_id', $this->playlist->id)->pluck('name')->all())->toBe(['Active'])
        ->and($this->playlist->fresh()->dynamic_groups_config)->toBe($rules);
});

it('keeps every rule in dynamic_groups_config when the sync runs with TMDB unconfigured', function () {
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(false);
    app()->instance(TmdbService::class, $tmdb);

    ['rules' => $rules] = seedConfigAndRowsForIssue1550Test($this);

    (new SyncDynamicGroups(playlistId: $this->playlist->id))->handle();

    expect(DynamicGroup::where('playlist_id', $this->playlist->id)->count())->toBe(0)
        ->and($this->playlist->fresh()->dynamic_groups_config)->toBe($rules);
});

it('rolls back the delete when removing the rule from the playlist fails', function () {
    ['target' => $target, 'rules' => $rules] = seedConfigAndRowsForIssue1550Test($this);

    $failingPlaylist = new class extends Playlist
    {
        public function saveQuietly(array $options = []): bool
        {
            throw new RuntimeException('Playlist save failed');
        }
    };
    $failingPlaylist->setRawAttributes($this->playlist->fresh()->getAttributes(), true);
    $failingPlaylist->exists = true;
    $target->setRelation('playlist', $failingPlaylist);

    expect(fn () => $target->delete())->toThrow(RuntimeException::class, 'Playlist save failed');

    expect(DynamicGroup::find($target->id))->not->toBeNull()
        ->and($this->playlist->fresh()->dynamic_groups_config)->toBe($rules);
});
