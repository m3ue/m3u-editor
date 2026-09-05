<?php

/**
 * Issue #1457 (Pass 1): a PlaylistAlias may wrap a MergedPlaylist so its output
 * can be handed out with replacement Xtream credentials. Per-alias group/category
 * filtering for merged aliases is covered by PlaylistAliasMergedPlaylistFilterTest.
 */

use App\Filament\Resources\PlaylistAliases\Pages\CreatePlaylistAlias;
use App\Filament\Resources\PlaylistAliases\Pages\EditPlaylistAlias;
use App\Http\Controllers\PlaylistGenerateController;
use App\Models\Channel;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'owner']);

    // Source A: an Xtream provider whose stream URLs should be credential-swapped.
    $this->xtreamSource = Playlist::factory()->for($this->user)->createQuietly([
        'xtream_config' => [
            'url' => 'http://provider.example.com:8080',
            'username' => 'olduser',
            'password' => 'oldpass',
        ],
    ]);

    // Source B: a plain M3U playlist whose URLs must be left untouched.
    $this->plainSource = Playlist::factory()->for($this->user)->createQuietly([
        'xtream_config' => null,
    ]);

    $this->merged = MergedPlaylist::factory()->for($this->user)->create();
    $this->merged->playlists()->attach($this->xtreamSource->id);
    $this->merged->playlists()->attach($this->plainSource->id, ['include_vod' => false]);

    $this->xtreamLive = Channel::factory()->create([
        'playlist_id' => $this->xtreamSource->id,
        'user_id' => $this->user->id,
        'group_id' => null,
        'enabled' => true,
        'is_vod' => false,
        'title' => 'Xtream Live',
        'url' => 'http://provider.example.com:8080/live/olduser/oldpass/1234.ts',
    ]);
    $this->xtreamVod = Channel::factory()->create([
        'playlist_id' => $this->xtreamSource->id,
        'user_id' => $this->user->id,
        'group_id' => null,
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Xtream VOD',
        'url' => 'http://provider.example.com:8080/movie/olduser/oldpass/9999.mp4',
    ]);
    $this->plainLive = Channel::factory()->create([
        'playlist_id' => $this->plainSource->id,
        'user_id' => $this->user->id,
        'group_id' => null,
        'enabled' => true,
        'is_vod' => false,
        'title' => 'Plain Live',
        'url' => 'http://cdn.example.net/plain/stream.m3u8',
    ]);
    // Dropped by the include_vod=false toggle on the plain source.
    $this->plainVod = Channel::factory()->create([
        'playlist_id' => $this->plainSource->id,
        'user_id' => $this->user->id,
        'group_id' => null,
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Plain VOD',
        'url' => 'http://cdn.example.net/plain/movie.mp4',
    ]);

    Series::factory()->create([
        'playlist_id' => $this->xtreamSource->id,
        'user_id' => $this->user->id,
        'enabled' => true,
    ]);

    $this->alias = PlaylistAlias::create([
        'merged_playlist_id' => $this->merged->id,
        'user_id' => $this->user->id,
        'name' => 'Merged Alias',
        'uuid' => Str::uuid()->toString(),
        'xtream_config' => [[
            'url' => 'http://provider.example.com:8080',
            'username' => 'newuser',
            'password' => 'newpass',
        ]],
    ]);
});

it('resolves the merged playlist as its effective playlist', function () {
    $effective = $this->alias->fresh()->getEffectivePlaylist();

    expect($effective)->toBeInstanceOf(MergedPlaylist::class)
        ->and($effective->id)->toBe($this->merged->id);
});

it('exposes the merged playlist content through the alias, honouring per-source toggles', function () {
    $alias = $this->alias->fresh();

    expect($alias->channels()->count())->toBe($this->merged->channels()->count())
        ->and($alias->channels()->pluck('channels.id')->sort()->values()->all())
        ->toBe(collect([$this->xtreamLive->id, $this->xtreamVod->id, $this->plainLive->id])->sort()->values()->all())
        ->and($alias->live_channels()->count())->toBe(2)
        ->and($alias->vod_channels()->count())->toBe(1)
        ->and($alias->series()->count())->toBe(1);
});

it('authorises the owner but not another user via the policy', function () {
    $alias = $this->alias->fresh();
    $other = User::factory()->create();

    expect($this->user->can('view', $alias))->toBeTrue()
        ->and($this->user->can('update', $alias))->toBeTrue()
        ->and($other->can('view', $alias))->toBeFalse()
        ->and($other->can('update', $alias))->toBeFalse();
});

it('swaps only the Xtream-source credentials, leaving plain provider URLs untouched', function () {
    $alias = $this->alias->fresh();

    expect($alias->transformChannelUrl($this->xtreamLive))
        ->toBe('http://provider.example.com:8080/live/newuser/newpass/1234.ts')
        ->and($alias->transformChannelUrl($this->plainLive))
        ->toBe('http://cdn.example.net/plain/stream.m3u8');
});

it('includes the merged live channels in the generated M3U and respects the plain-source VOD toggle', function () {
    $content = $this->get("/{$this->alias->uuid}/playlist.m3u")->assertOk()->streamedContent();

    expect($content)->toContain('Xtream Live')
        ->and($content)->toContain('Plain Live')
        ->and($content)->not->toContain('Plain VOD');
});

it('builds a streaming channel query that carries the merged live channels', function () {
    $ids = PlaylistGenerateController::getChannelQuery($this->alias->fresh())
        ->get()
        ->pluck('id')
        ->all();

    expect($ids)->toContain($this->xtreamLive->id)
        ->and($ids)->toContain($this->plainLive->id)
        ->and($ids)->not->toContain($this->plainVod->id);
});

it('returns the merged live channels from the Xtream API', function () {
    $response = $this->getJson(route('xtream.api.player', [
        'action' => 'get_live_streams',
        'username' => 'owner',
        'password' => $this->alias->uuid,
    ]));

    $response->assertOk();
    $names = collect($response->json())->pluck('name');

    expect($names)->toContain('Xtream Live')
        ->and($names)->toContain('Plain Live');
});

it('serves an EPG document for the merged alias', function () {
    $this->get("/{$this->alias->uuid}/epg.xml")->assertOk();
});

it('creates a merged playlist alias through the Filament resource', function () {
    $this->actingAs($this->user);

    Livewire::test(CreatePlaylistAlias::class)
        ->fillForm([
            'name' => 'Created Merged Alias',
            'source_type' => 'merged_playlist',
            'source_id' => $this->merged->id,
            'merged_playlist_id' => $this->merged->id,
            'xtream_config' => [[
                'url' => 'http://provider.example.com:8080',
                'username' => 'newuser',
                'password' => 'newpass',
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $alias = PlaylistAlias::where('name', 'Created Merged Alias')->firstOrFail();

    expect($alias->merged_playlist_id)->toBe($this->merged->id)
        ->and($alias->playlist_id)->toBeNull()
        ->and($alias->custom_playlist_id)->toBeNull();
});

it('translates the type + playlist picker back from a saved merged alias', function () {
    $this->actingAs($this->user);

    Livewire::test(EditPlaylistAlias::class, ['record' => $this->alias->getRouteKey()])
        ->assertFormSet([
            'source_type' => 'merged_playlist',
            'source_id' => $this->merged->id,
        ]);
});

it('requires a playlist to be chosen', function () {
    $this->actingAs($this->user);

    Livewire::test(CreatePlaylistAlias::class)
        ->fillForm([
            'name' => 'Invalid Alias',
            'source_type' => 'merged_playlist',
            'xtream_config' => [[
                'url' => 'http://provider.example.com:8080',
                'username' => 'newuser',
                'password' => 'newpass',
            ]],
        ])
        ->call('create')
        ->assertHasFormErrors(['source_id']);
});
