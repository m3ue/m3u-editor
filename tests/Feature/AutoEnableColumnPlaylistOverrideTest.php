<?php

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\VodGroups\Pages\ListVodGroups;
use App\Models\Category;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Filament\Tables\Columns\ToggleColumn;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

dataset('auto enable tables', [
    'live groups' => [ListGroups::class, 'enable_channels', fn (Playlist $playlist) => Group::factory()->for($playlist)->for($playlist->user)->create(['type' => 'live', 'enabled' => false])],
    'vod groups' => [ListVodGroups::class, 'enable_vod_channels', fn (Playlist $playlist) => Group::factory()->for($playlist)->for($playlist->user)->create(['type' => 'vod', 'enabled' => false])],
    'series categories' => [ListCategories::class, 'enable_series', fn (Playlist $playlist) => Category::factory()->create(['user_id' => $playlist->user_id, 'playlist_id' => $playlist->id, 'enabled' => false])],
]);

it('shows auto enable as on and locked when the playlist enables new items', function (string $page, string $playlistFlag, Closure $makeRecord) {
    $playlist = Playlist::factory()->createQuietly(['user_id' => $this->user->id, $playlistFlag => true]);
    $record = $makeRecord($playlist);

    Livewire::test($page)
        ->loadTable()
        ->assertTableColumnStateSet('enabled', true, $record)
        ->assertTableColumnExists('enabled', fn (ToggleColumn $column): bool => $column->isDisabled(), $record);

    expect($record->fresh()->enabled)->toBeFalsy();
})->with('auto enable tables');

it('uses the record value and stays editable when the playlist does not enable new items', function (string $page, string $playlistFlag, Closure $makeRecord) {
    $playlist = Playlist::factory()->createQuietly(['user_id' => $this->user->id, $playlistFlag => false]);
    $record = $makeRecord($playlist);

    Livewire::test($page)
        ->loadTable()
        ->assertTableColumnStateSet('enabled', false, $record)
        ->assertTableColumnExists('enabled', fn (ToggleColumn $column): bool => ! $column->isDisabled(), $record);
})->with('auto enable tables');

it('does not lock vod groups when only live channels are auto enabled', function () {
    $playlist = Playlist::factory()->createQuietly(['user_id' => $this->user->id, 'enable_channels' => true, 'enable_vod_channels' => false]);
    $group = Group::factory()->for($playlist)->for($this->user)->create(['type' => 'vod', 'enabled' => false]);

    Livewire::test(ListVodGroups::class)
        ->loadTable()
        ->assertTableColumnStateSet('enabled', false, $group)
        ->assertTableColumnExists('enabled', fn (ToggleColumn $column): bool => ! $column->isDisabled(), $group);
});
