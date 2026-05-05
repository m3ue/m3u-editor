<?php

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\Series;
use App\Models\SourceCategory;
use App\Models\User;
use App\Services\PlaylistService;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeAlias(User $user, Playlist $playlist, array $overrides = []): PlaylistAlias
{
    return PlaylistAlias::create(array_merge([
        'name' => 'Test Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'xtream_config' => null,
    ], $overrides));
}

// ── resolvePlaylistByUuid ─────────────────────────────────────────────────────

describe('resolvePlaylistByUuid', function () {
    it('resolves a playlist alias by UUID', function () {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();
        $alias = makeAlias($user, $playlist);

        $result = (new PlaylistService)->resolvePlaylistByUuid($alias->uuid);

        expect($result)->toBeInstanceOf(PlaylistAlias::class)
            ->and($result->id)->toBe($alias->id);
    });

    it('returns null for an unknown UUID', function () {
        $result = (new PlaylistService)->resolvePlaylistByUuid(fake()->uuid());

        expect($result)->toBeNull();
    });
});

// ── authenticate() ────────────────────────────────────────────────────────────

describe('PlaylistService::authenticate() with aliases', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create();
    });

    it('authenticates via alias direct credentials (Method 1b)', function () {
        $alias = makeAlias($this->user, $this->playlist, [
            'username' => 'aliasuser',
            'password' => 'aliaspass',
        ]);

        $result = (new PlaylistService)->authenticate('aliasuser', 'aliaspass');

        expect($result)->toBeArray()
            ->and($result[0])->toBeInstanceOf(PlaylistAlias::class)
            ->and($result[0]->id)->toBe($alias->id)
            ->and($result[1])->toBe('alias_auth');
    });

    it('authenticates via PlaylistAuth assigned to alias (Method 1)', function () {
        $alias = makeAlias($this->user, $this->playlist);

        $auth = PlaylistAuth::factory()->create([
            'username' => 'authuser',
            'password' => 'authpass',
            'enabled' => true,
            'user_id' => $this->user->id,
        ]);
        $auth->assignTo($alias);

        $result = (new PlaylistService)->authenticate('authuser', 'authpass');

        expect($result)->toBeArray()
            ->and($result[0])->toBeInstanceOf(PlaylistAlias::class)
            ->and($result[0]->id)->toBe($alias->id)
            ->and($result[1])->toBe('playlist_auth');
    });

    it('PlaylistAuth (Method 1) is not short-circuited by an expired alias with the same credentials', function () {
        // Core regression for the Method 1b control-flow bug:
        // Before the fix, Method 1b ran unconditionally. An expired alias matching
        // the same credentials as a valid PlaylistAuth would cause authenticate()
        // to return false, denying access even though Method 1 had succeeded.
        $otherPlaylist = Playlist::factory()->for($this->user)->create();

        $auth = PlaylistAuth::factory()->create([
            'username' => 'shared_user',
            'password' => 'shared_pass',
            'enabled' => true,
            'user_id' => $this->user->id,
        ]);
        $auth->assignTo($otherPlaylist);

        // Expired alias with the same credentials — must not block the PlaylistAuth path
        makeAlias($this->user, $this->playlist, [
            'username' => 'shared_user',
            'password' => 'shared_pass',
            'expires_at' => now()->subDay(),
        ]);

        $result = (new PlaylistService)->authenticate('shared_user', 'shared_pass');

        expect($result)->toBeArray()
            ->and($result[1])->toBe('playlist_auth')
            ->and($result[0]->id)->toBe($otherPlaylist->id);
    });

    it('rejects expired alias direct credentials', function () {
        makeAlias($this->user, $this->playlist, [
            'username' => 'expireduser',
            'password' => 'expiredpass',
            'expires_at' => now()->subDay(),
        ]);

        $result = (new PlaylistService)->authenticate('expireduser', 'expiredpass');

        // authenticate() returns [null, 'none', ...] when no method matches
        expect($result[0])->toBeNull();
    });

    it('returns null playlist for completely unknown credentials', function () {
        $result = (new PlaylistService)->authenticate('nobody', 'wrong');

        expect($result[0])->toBeNull();
    });
});

// ── Xtream API panel action ───────────────────────────────────────────────────

describe('Xtream API panel action with aliases', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create();
    });

    it('returns panel response when authenticated via alias direct credentials', function () {
        makeAlias($this->user, $this->playlist, [
            'username' => 'aliasuser',
            'password' => 'aliaspass',
        ]);

        $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
            'action' => 'panel',
            'username' => 'aliasuser',
            'password' => 'aliaspass',
        ]));

        $response->assertOk()
            ->assertJsonStructure(['user_info', 'server_info']);
    });

    it('returns panel response when authenticated via PlaylistAuth assigned to alias', function () {
        $alias = makeAlias($this->user, $this->playlist);

        $auth = PlaylistAuth::factory()->create([
            'username' => 'authuser',
            'password' => 'authpass',
            'enabled' => true,
            'user_id' => $this->user->id,
        ]);
        $auth->assignTo($alias);

        $response = $this->getJson(route('xtream.api.player').'?'.http_build_query([
            'action' => 'panel',
            'username' => 'authuser',
            'password' => 'authpass',
        ]));

        $response->assertOk()
            ->assertJsonStructure(['user_info', 'server_info']);
    });
});

// ── PlaylistAuth assignment ───────────────────────────────────────────────────

describe('PlaylistAuth::assignTo() with PlaylistAlias', function () {
    it('can assign a PlaylistAuth to a PlaylistAlias', function () {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();
        $alias = makeAlias($user, $playlist);

        $auth = PlaylistAuth::factory()->create([
            'enabled' => true,
            'user_id' => $user->id,
        ]);

        $auth->assignTo($alias);

        expect($alias->playlistAuths()->where('enabled', true)->count())->toBe(1)
            ->and($auth->getAssignedModel()->id)->toBe($alias->id);
    });

    it('throws when assigning PlaylistAuth to an unsupported model type', function () {
        $auth = PlaylistAuth::factory()->create();

        expect(fn () => $auth->assignTo(new User))->toThrow(InvalidArgumentException::class);
    });
});

// ── Delegating accessors ──────────────────────────────────────────────────────

describe('PlaylistAlias delegating accessors', function () {
    it('delegates include_vod_in_m3u, include_series_in_m3u, channel_start, dummy_epg, dummy_epg_category to effective playlist', function () {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create([
            'include_vod_in_m3u' => true,
            'include_series_in_m3u' => true,
            'channel_start' => 5,
            'dummy_epg' => true,
            'dummy_epg_category' => true,
        ]);

        $alias = makeAlias($user, $playlist);

        expect($alias->include_vod_in_m3u)->toBeTrue()
            ->and($alias->include_series_in_m3u)->toBeTrue()
            ->and($alias->channel_start)->toBe(5)
            ->and($alias->dummy_epg)->toBeTrue()
            ->and($alias->dummy_epg_category)->toBeTrue();
    });

    it('returns sensible defaults when alias has no effective playlist', function () {
        $user = User::factory()->create();
        $alias = PlaylistAlias::create([
            'name' => 'Orphan Alias',
            'uuid' => fake()->uuid(),
            'user_id' => $user->id,
            'playlist_id' => null,
            'custom_playlist_id' => null,
            'xtream_config' => null,
        ]);

        expect($alias->include_vod_in_m3u)->toBeFalse()
            ->and($alias->include_series_in_m3u)->toBeFalse()
            ->and($alias->channel_start)->toBe(1)
            ->and($alias->dummy_epg)->toBeFalse()
            ->and($alias->dummy_epg_category)->toBeFalse();
    });
});

// ── xtreamConfig accessor ─────────────────────────────────────────────────────

describe('PlaylistAlias xtream_config accessor', function () {
    it('returns an empty array for a null xtream_config without TypeError', function () {
        $user = User::factory()->create();
        $alias = PlaylistAlias::create([
            'name' => 'Test Alias',
            'uuid' => fake()->uuid(),
            'user_id' => $user->id,
            'xtream_config' => null,
        ]);

        expect($alias->xtream_config)->toBe([]);
    });

    it('returns an empty array for an empty-string xtream_config', function () {
        $user = User::factory()->create();
        $alias = PlaylistAlias::create([
            'name' => 'Test Alias',
            'uuid' => fake()->uuid(),
            'user_id' => $user->id,
            'xtream_config' => null,
        ]);

        // Force the raw attribute to an empty string to simulate legacy/corrupt data
        $alias->setRawAttributes(['xtream_config' => ''] + $alias->getRawOriginal());

        expect($alias->xtream_config)->toBe([]);
    });
});

// ── group_filter — hasGroupFilter() ──────────────────────────────────────────

describe('PlaylistAlias::hasGroupFilter()', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create();
    });

    it('returns false when group_filter is null', function () {
        $alias = makeAlias($this->user, $this->playlist, ['group_filter' => null]);

        expect($alias->hasGroupFilter())->toBeFalse();
    });

    it('returns false when all filter arrays are empty', function () {
        $alias = makeAlias($this->user, $this->playlist, [
            'group_filter' => [
                'selected_groups' => [],
                'selected_vod_groups' => [],
                'selected_categories' => [],
            ],
        ]);

        expect($alias->hasGroupFilter())->toBeFalse();
    });

    it('returns true when selected_groups has entries', function () {
        $alias = makeAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_groups' => ['Sports']],
        ]);

        expect($alias->hasGroupFilter())->toBeTrue();
    });

    it('returns true when selected_vod_groups has entries', function () {
        $alias = makeAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_vod_groups' => ['Movies']],
        ]);

        expect($alias->hasGroupFilter())->toBeTrue();
    });

    it('returns true when selected_categories has entries', function () {
        $alias = makeAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_categories' => ['Drama']],
        ]);

        expect($alias->hasGroupFilter())->toBeTrue();
    });
});

// ── group_filter — channels() live filter ────────────────────────────────────

describe('PlaylistAlias::channels() live group filter', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create();
    });

    it('returns all live channels when no group filter is set', function () {
        $alias = makeAlias($this->user, $this->playlist);

        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'Sports', 'is_vod' => false, 'enabled' => true,
        ]);
        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'News', 'is_vod' => false, 'enabled' => true,
        ]);

        expect($alias->channels()->count())->toBe(2);
    });

    it('filters live channels to the allowed groups', function () {
        $alias = makeAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_groups' => ['Sports']],
        ]);

        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'Sports', 'is_vod' => false, 'enabled' => true,
        ]);
        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'News', 'is_vod' => false, 'enabled' => true,
        ]);

        $ids = $alias->channels()->pluck('channels.group_internal');
        expect($ids)->toContain('Sports')
            ->and($ids)->not->toContain('News');
    });

    it('lets VOD channels through when a live group filter is active', function () {
        $alias = makeAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_groups' => ['Sports']],
        ]);

        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'Movies', 'is_vod' => true, 'enabled' => true,
        ]);

        // VOD channel should be visible even though its group is not in selected_groups
        expect($alias->channels()->where('is_vod', true)->count())->toBe(1);
    });
});

// ── group_filter — channels() VOD filter ─────────────────────────────────────

describe('PlaylistAlias::channels() VOD group filter', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create();
    });

    it('filters VOD channels to the allowed VOD groups', function () {
        $alias = makeAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_vod_groups' => ['Movies']],
        ]);

        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'Movies', 'is_vod' => true, 'enabled' => true,
        ]);
        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'Documentaries', 'is_vod' => true, 'enabled' => true,
        ]);

        $ids = $alias->channels()->where('is_vod', true)->pluck('channels.group_internal');
        expect($ids)->toContain('Movies')
            ->and($ids)->not->toContain('Documentaries');
    });

    it('lets live channels through when a VOD group filter is active', function () {
        $alias = makeAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_vod_groups' => ['Movies']],
        ]);

        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'News', 'is_vod' => false, 'enabled' => true,
        ]);

        // Live channel should be visible even though it is not in selected_vod_groups
        expect($alias->channels()->where('is_vod', false)->count())->toBe(1);
    });
});

// ── group_filter — series() category filter ───────────────────────────────────

describe('PlaylistAlias::series() category filter', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create();
    });

    it('returns all series when no category filter is set', function () {
        $alias = makeAlias($this->user, $this->playlist);

        Series::factory()->for($this->playlist)->for($this->user)->create(['source_category_id' => 10, 'enabled' => true]);
        Series::factory()->for($this->playlist)->for($this->user)->create(['source_category_id' => 20, 'enabled' => true]);

        expect($alias->series()->count())->toBe(2);
    });

    it('filters series to the allowed category names', function () {
        SourceCategory::create([
            'playlist_id' => $this->playlist->id,
            'source_category_id' => 10,
            'name' => 'Drama',
        ]);
        SourceCategory::create([
            'playlist_id' => $this->playlist->id,
            'source_category_id' => 20,
            'name' => 'Comedy',
        ]);

        $alias = makeAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_categories' => ['Drama']],
        ]);

        $drama = Series::factory()->for($this->playlist)->for($this->user)->create(['source_category_id' => 10, 'enabled' => true]);
        Series::factory()->for($this->playlist)->for($this->user)->create(['source_category_id' => 20, 'enabled' => true]);

        $ids = $alias->series()->pluck('series.id');
        expect($ids)->toContain($drama->id)
            ->and($ids->count())->toBe(1);
    });

    it('memoises the source_category_id lookup across multiple series() calls', function () {
        SourceCategory::create([
            'playlist_id' => $this->playlist->id,
            'source_category_id' => 10,
            'name' => 'Drama',
        ]);

        $alias = makeAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_categories' => ['Drama']],
        ]);

        Series::factory()->for($this->playlist)->for($this->user)->create(['source_category_id' => 10, 'enabled' => true]);

        // Two calls should resolve to the same IDs without hitting the DB a second time
        $first = $alias->series()->pluck('series.id');
        $second = $alias->series()->pluck('series.id');

        expect($first->toArray())->toBe($second->toArray())
            ->and($alias->resolvedCategoryIds)->toBe([10]);
    });
});
