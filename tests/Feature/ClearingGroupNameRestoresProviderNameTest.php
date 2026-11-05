<?php

/**
 * Clearing the inline name column on a group or category resets it to the provider's
 * name, rather than failing the NOT NULL constraint on groups.name / categories.name.
 */

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\VodGroups\Pages\ListVodGroups;
use App\Livewire\EasyEditor\GroupsPane;
use App\Models\Category;
use App\Models\Group;
use App\Models\Playlist;
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

    $this->playlist = Playlist::factory()->for($this->user)->create();
});

function renamedGroup(Playlist $playlist, User $user, string $type = 'live'): Group
{
    return Group::factory()->for($playlist)->for($user)->create([
        'name' => 'My Sports',
        'name_internal' => 'Provider Sports',
        'type' => $type,
    ]);
}

it('resets a live group from the groups table', function (mixed $clearedValue) {
    $group = renamedGroup($this->playlist, $this->user);

    Livewire::test(ListGroups::class)
        ->call('updateTableColumnState', 'name', (string) $group->id, $clearedValue)
        ->assertHasNoErrors();

    expect($group->fresh()->name)->toBe('Provider Sports');
})->with([
    'empty' => '',
    'null' => null,
    'whitespace' => '   ',
]);

it('resets a VOD group from the VOD groups table', function () {
    $group = renamedGroup($this->playlist, $this->user, 'vod');

    Livewire::test(ListVodGroups::class)
        ->call('updateTableColumnState', 'name', (string) $group->id, '');

    expect($group->fresh()->name)->toBe('Provider Sports');
});

it('resets a category from the categories table', function () {
    $category = Category::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Laughs',
        'name_internal' => 'Comedy',
    ]);

    Livewire::test(ListCategories::class)
        ->call('updateTableColumnState', 'name', (string) $category->id, '');

    expect($category->fresh()->name)->toBe('Comedy');
});

it('resets a group from the Easy Editor', function () {
    $group = renamedGroup($this->playlist, $this->user);

    Livewire::test(GroupsPane::class, ['playlistId' => $this->playlist->id, 'contentType' => 'live'])
        ->call('updateTableColumnState', 'name', (string) $group->id, '');

    expect($group->fresh()->name)->toBe('Provider Sports');
});

it('still saves a new name typed into the column', function () {
    $group = renamedGroup($this->playlist, $this->user);

    Livewire::test(ListGroups::class)
        ->call('updateTableColumnState', 'name', (string) $group->id, 'Sports HD');

    expect($group->fresh())
        ->name->toBe('Sports HD')
        ->name_internal->toBe('Provider Sports');
});

it('keeps the current name when a legacy group has no provider name', function () {
    $group = renamedGroup($this->playlist, $this->user);
    $group->forceFill(['name_internal' => null])->saveQuietly();

    $group->update(['name' => null]);

    expect($group->fresh()->name)->toBe('My Sports');
});

it('leaves the empty name the import gives an unnamed provider group alone', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => '',
        'name_internal' => '',
    ]);

    expect($group->fresh()->name)->toBe('');
});
