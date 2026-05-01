<?php

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Models\Playlist;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->actingAs($this->user);
});

it('renders the sort_order column on the categories table', function () {
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'sort_order' => 5,
    ]);

    Livewire::test(ListCategories::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$category])
        ->assertTableColumnExists('sort_order');
});

it('sorts categories by sort_order ascending by default', function () {
    $third = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'sort_order' => 30,
    ]);
    $first = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'sort_order' => 10,
    ]);
    $second = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'sort_order' => 20,
    ]);

    Livewire::test(ListCategories::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$first, $second, $third], inOrder: true);
});

it('persists sort_order edits made through the inline column', function () {
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'sort_order' => 9999,
    ]);

    Livewire::test(ListCategories::class)
        ->loadTable()
        ->call('updateTableColumnState', 'sort_order', (string) $category->getKey(), '7');

    expect($category->refresh()->sort_order)->toEqual(7);
});

it('persists sort_order edits made through the edit form', function () {
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'sort_order' => 9999,
    ]);

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->assertFormFieldExists('sort_order')
        ->fillForm(['sort_order' => 42])
        ->call('save');

    expect($category->refresh()->sort_order)->toEqual(42);
});
