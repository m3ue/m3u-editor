<?php

/**
 * The Processing pickers on the playlist edit form show a renamed group or category
 * alongside its provider name, while the saved selection stays on the provider name.
 */

use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Models\Category;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\SourceCategory;
use App\Models\SourceGroup;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->playlist = Playlist::factory()->for($this->user)->create([
        'xtream' => true,
        'user_agent' => 'VLC/3.0',
        'xtream_config' => [
            'url' => 'http://provider.test',
            'username' => 'user',
            'password' => 'pass',
            'output' => 'ts',
        ],
        'import_prefs' => [
            'preprocess' => true,
            'selected_groups' => ['Sports'],
            'selected_vod_groups' => ['Movies'],
            'selected_categories' => ['Comedy'],
        ],
    ]);

    SourceGroup::create(['playlist_id' => $this->playlist->id, 'name' => 'Sports', 'type' => 'live']);
    SourceGroup::create(['playlist_id' => $this->playlist->id, 'name' => 'Movies', 'type' => 'vod']);
    SourceCategory::create(['playlist_id' => $this->playlist->id, 'name' => 'Comedy', 'source_category_id' => 1]);

    Group::factory()->for($this->playlist)->for($this->user)->create(['name' => 'UK Sports HD', 'name_internal' => 'Sports', 'type' => 'live']);
    Group::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Cinema', 'name_internal' => 'Movies', 'type' => 'vod']);
    Category::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Laughs', 'name_internal' => 'Comedy']);
});

it('labels selected items with both names and still saves the provider names', function () {
    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->assertSee('UK Sports HD (provider: Sports)')
        ->assertSee('Cinema (provider: Movies)')
        ->assertSee('Laughs (provider: Comedy)')
        ->call('save')
        ->assertHasNoFormErrors();

    $importPrefs = $this->playlist->fresh()->import_prefs;

    expect($importPrefs['selected_groups'])->toBe(['Sports'])
        ->and($importPrefs['selected_vod_groups'])->toBe(['Movies'])
        ->and($importPrefs['selected_categories'])->toBe(['Comedy']);
});
