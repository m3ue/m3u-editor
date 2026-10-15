<?php

use App\Filament\Resources\PlaylistAliases\Pages\CreatePlaylistAlias;
use App\Filament\Resources\PlaylistAliases\Pages\EditPlaylistAlias;
use App\Filament\Resources\PlaylistAliases\Pages\ListPlaylistAliases;
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
