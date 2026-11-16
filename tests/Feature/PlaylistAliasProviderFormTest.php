<?php

use App\Filament\Resources\PlaylistAliases\Pages\CreatePlaylistAlias;
use App\Filament\Resources\PlaylistAliases\Pages\EditPlaylistAlias;
use App\Filament\Resources\PlaylistAliases\Pages\ListPlaylistAliases;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->playlist = Playlist::factory()->for($this->user)->createQuietly(['xtream_config' => null]);
    $this->alias = PlaylistAlias::create([
        'name' => 'Provider Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'xtream_config' => [[
            'url' => 'http://provider.example.com:8080',
            'username' => 'user',
            'password' => 'pass',
        ]],
    ]);
});

it('saves a replacement-only provider entry without credentials', function () {
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(EditPlaylistAlias::class, ['record' => $this->alias->getRouteKey()])
        ->fillForm([
            'xtream_config' => [[
                'url' => 'http://provider.example.com:8080',
                'username' => null,
                'password' => null,
                'replace_url_enabled' => true,
                'replace_url' => 'http://vpn.provider.example.com:8080',
            ]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->alias->fresh()->xtream_config[0])->toMatchArray([
        'url' => 'http://provider.example.com:8080',
        'replace_url_enabled' => true,
        'replace_url' => 'http://vpn.provider.example.com:8080',
    ]);

    $undoRepeaterFake();
});

it('requires credentials when the provider url is not replaced', function () {
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(EditPlaylistAlias::class, ['record' => $this->alias->getRouteKey()])
        ->fillForm([
            'xtream_config' => [[
                'url' => 'http://provider.example.com:8080',
                'username' => null,
                'password' => null,
                'replace_url_enabled' => false,
            ]],
        ])
        ->call('save')
        ->assertHasFormErrors([
            'xtream_config.0.username' => 'required',
            'xtream_config.0.password' => 'required',
        ]);

    $undoRepeaterFake();
});

it('requires credentials as a pair and a replacement url when replacing', function () {
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(EditPlaylistAlias::class, ['record' => $this->alias->getRouteKey()])
        ->fillForm([
            'xtream_config' => [[
                'url' => 'http://provider.example.com:8080',
                'username' => 'user',
                'password' => null,
                'replace_url_enabled' => true,
                'replace_url' => null,
            ]],
        ])
        ->call('save')
        ->assertHasFormErrors([
            'xtream_config.0.password' => 'required',
            'xtream_config.0.replace_url' => 'required',
        ]);

    $undoRepeaterFake();
});

it('seeds uuid-keyed provider entries with every field when creating', function () {
    // The create form replaces the repeater state when a source is picked. Entries
    // keyed 0..n without the toggle key left the replacement toggle unreactive in the
    // browser, so they must look like the ones the repeater builds for a record.
    $component = Livewire::test(CreatePlaylistAlias::class)
        ->fillForm(['source_type' => 'playlist', 'source_id' => $this->playlist->id]);

    $entries = $component->get('data.xtream_config');
    $key = array_key_first($entries);

    expect($entries)->toHaveCount(1)
        ->and(Str::isUuid($key))->toBeTrue()
        ->and($entries[$key])->toBe([
            'url' => '',
            'username' => '',
            'password' => '',
            'replace_url_enabled' => false,
            'replace_url' => null,
        ]);

    $component
        ->fillForm([
            'name' => 'VPN Alias',
            "xtream_config.{$key}.url" => 'http://provider.example.com:8080',
            "xtream_config.{$key}.replace_url_enabled" => true,
        ])
        ->assertFormFieldIsVisible("xtream_config.{$key}.replace_url")
        ->fillForm(["xtream_config.{$key}.replace_url" => 'http://vpn.provider.example.com:8080'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PlaylistAlias::where('name', 'VPN Alias')->first()?->xtream_config[0])->toMatchArray([
        'url' => 'http://provider.example.com:8080',
        'replace_url_enabled' => true,
        'replace_url' => 'http://vpn.provider.example.com:8080',
    ]);
});

it('saves fields from every tab of the create page and redirects to the edit page', function () {
    $component = Livewire::test(CreatePlaylistAlias::class)
        ->fillForm(['source_type' => 'playlist', 'source_id' => $this->playlist->id]);

    $key = array_key_first($component->get('data.xtream_config'));

    $component
        ->fillForm([
            'name' => 'Tabbed Alias',
            "xtream_config.{$key}.url" => 'http://provider.example.com:8080',
            "xtream_config.{$key}.username" => 'provider-user',
            "xtream_config.{$key}.password" => 'provider-pass',
            // Lives on the Auth tab, which is not the active tab when the form opens.
            'username' => 'tabbed-user',
            'password' => 'tabbed-pass',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $alias = PlaylistAlias::where('name', 'Tabbed Alias')->sole();

    expect($alias->username)->toBe('tabbed-user');
    $component->assertRedirect(EditPlaylistAlias::getUrl(['record' => $alias]));
});

it('opens the alias on the full edit page for its owner only', function () {
    $alias = PlaylistAlias::create([
        'name' => 'Page Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'xtream_config' => null,
    ]);

    $this->get(EditPlaylistAlias::getUrl(['record' => $alias]))->assertSuccessful();

    Livewire::test(ListPlaylistAliases::class)
        ->assertTableActionHasUrl('edit', EditPlaylistAlias::getUrl(['record' => $alias]), $alias)
        ->assertActionHasUrl('create', CreatePlaylistAlias::getUrl());

    $this->get(CreatePlaylistAlias::getUrl())->assertSuccessful();

    $this->actingAs(User::factory()->create());
    $this->get(EditPlaylistAlias::getUrl(['record' => $alias]))->assertNotFound();
});

it('prefills a direct m3u alias from its stream urls and swaps their credentials', function (string $streamUrl, string $expectedUrl) {
    // The playlist URL points at a different host and path than the streams, so
    // the prefill must come from the stream URL.
    $this->playlist->updateQuietly([
        'url' => 'http://playlist.example.com/lists/get.php?type=m3u_plus',
        'xtream_config' => null,
    ]);
    $channel = Channel::factory()->createQuietly([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => null,
        'url' => $streamUrl,
    ]);

    $component = Livewire::test(CreatePlaylistAlias::class)
        ->fillForm(['source_type' => 'playlist', 'source_id' => $this->playlist->id]);

    $entries = $component->get('data.xtream_config');

    expect(array_column($entries, 'url'))->toBe(['http://stream.example.com:8080']);

    $key = array_key_first($entries);

    $component
        ->fillForm([
            'name' => 'Direct M3U Alias',
            "xtream_config.{$key}.username" => 'newuser',
            "xtream_config.{$key}.password" => 'newpass',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $alias = PlaylistAlias::where('name', 'Direct M3U Alias')->sole();

    expect($alias->transformChannelUrl($channel))->toBe($expectedUrl);
})->with([
    'prefixed stream url' => ['http://stream.example.com:8080/live/olduser/oldpass/1234.ts', 'http://stream.example.com:8080/live/newuser/newpass/1234.ts'],
    'prefix-less stream url' => ['http://stream.example.com:8080/olduser/oldpass/1234.ts', 'http://stream.example.com:8080/newuser/newpass/1234.ts'],
]);

it('skips m3u sources whose streams carry no credentials', function () {
    $publicSource = Playlist::factory()->for($this->user)->createQuietly(['xtream_config' => null]);
    Channel::factory()->createQuietly([
        'playlist_id' => $publicSource->id,
        'user_id' => $this->user->id,
        'group_id' => null,
        'url' => 'https://cdn.example.com/streams/news/index.m3u8',
    ]);
    $xtreamSource = Playlist::factory()->for($this->user)->createQuietly([
        'xtream' => true,
        'xtream_config' => ['url' => 'http://xtream-provider.example.com:8080', 'username' => 'u', 'password' => 'p'],
    ]);

    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach([$publicSource->id, $xtreamSource->id]);

    $component = Livewire::test(CreatePlaylistAlias::class)
        ->fillForm(['source_type' => 'merged_playlist', 'source_id' => $merged->id]);

    expect(array_column($component->get('data.xtream_config'), 'url'))
        ->toBe(['http://xtream-provider.example.com:8080']);
});

it('collapses sources sharing a provider url into one entry', function () {
    $m3uSource = Playlist::factory()->for($this->user)->createQuietly(['xtream_config' => null]);
    Channel::factory()->createQuietly([
        'playlist_id' => $m3uSource->id,
        'user_id' => $this->user->id,
        'group_id' => null,
        'url' => 'http://provider.example.com:8080/live/m3uuser/m3upass/1234.ts',
    ]);
    $xtreamSource = Playlist::factory()->for($this->user)->createQuietly([
        'xtream' => true,
        'xtream_config' => ['url' => 'http://provider.example.com:8080/', 'username' => 'u', 'password' => 'p'],
    ]);

    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach([$m3uSource->id, $xtreamSource->id]);

    $component = Livewire::test(CreatePlaylistAlias::class)
        ->fillForm(['source_type' => 'merged_playlist', 'source_id' => $merged->id]);

    expect(array_column($component->get('data.xtream_config'), 'url'))
        ->toBe(['http://provider.example.com:8080']);
});

it('swaps m3u and xtream credentials after creating a multi-source alias', function (string $sourceType) {
    $m3uSource = Playlist::factory()->for($this->user)->createQuietly([
        'name' => 'M3U Live',
        'url' => 'http://m3u-provider.example.com:8080/get.php?username=m3uold&password=m3uoldpass&type=m3u_plus&output=ts',
        'xtream' => false,
        'xtream_config' => null,
    ]);
    $xtreamSource = Playlist::factory()->for($this->user)->createQuietly([
        'name' => 'Xtream VOD',
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://xtream-provider.example.com:8080',
            'username' => 'xtreamold',
            'password' => 'xtreamoldpass',
        ],
    ]);
    $m3uLive = Channel::factory()->createQuietly([
        'playlist_id' => $m3uSource->id,
        'user_id' => $this->user->id,
        'group_id' => null,
        'is_vod' => false,
        'url' => 'http://m3u-provider.example.com:8080/live/m3uold/m3uoldpass/1234.ts',
    ]);
    $xtreamVod = Channel::factory()->createQuietly([
        'playlist_id' => $xtreamSource->id,
        'user_id' => $this->user->id,
        'group_id' => null,
        'is_vod' => true,
        'url' => 'http://xtream-provider.example.com:8080/movie/xtreamold/xtreamoldpass/9999.mp4',
    ]);

    if ($sourceType === 'merged_playlist') {
        $source = MergedPlaylist::factory()->for($this->user)->create();
        $source->playlists()->attach([
            $m3uSource->id => ['include_live' => true, 'include_vod' => false, 'include_series' => false],
            $xtreamSource->id => ['include_live' => false, 'include_vod' => true, 'include_series' => true],
        ]);
    } else {
        $source = CustomPlaylist::factory()->for($this->user)->create();
        $source->channels()->attach([$m3uLive->id, $xtreamVod->id]);
    }

    $component = Livewire::test(CreatePlaylistAlias::class)
        ->fillForm(['source_type' => $sourceType, 'source_id' => $source->id]);

    $entries = $component->get('data.xtream_config');
    $providerUrls = collect($entries)->pluck('url')->sort()->values()->all();

    expect($providerUrls)->toBe([
        'http://m3u-provider.example.com:8080',
        'http://xtream-provider.example.com:8080',
    ]);

    foreach ($entries as $key => $entry) {
        $prefix = $entry['url'] === 'http://m3u-provider.example.com:8080' ? 'm3u' : 'xtream';
        $component->fillForm([
            "xtream_config.{$key}.username" => "{$prefix}new",
            "xtream_config.{$key}.password" => "{$prefix}newpass",
        ]);
    }

    $component
        ->fillForm(['name' => "Multi-source {$sourceType} Alias"])
        ->call('create')
        ->assertHasNoFormErrors();

    $alias = PlaylistAlias::where('name', "Multi-source {$sourceType} Alias")->sole();

    expect($alias->transformChannelUrl($m3uLive))
        ->toBe('http://m3u-provider.example.com:8080/live/m3unew/m3unewpass/1234.ts')
        ->and($alias->transformChannelUrl($xtreamVod))
        ->toBe('http://xtream-provider.example.com:8080/movie/xtreamnew/xtreamnewpass/9999.mp4');
})->with([
    'merged playlist' => 'merged_playlist',
    'custom playlist' => 'custom_playlist',
]);
