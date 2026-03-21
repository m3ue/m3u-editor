<?php

use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
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
