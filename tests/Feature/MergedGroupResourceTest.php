<?php

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\MergedCategories\Pages\ListMergedCategories;
use App\Filament\Resources\MergedGroups\Pages\ListMergedGroups;
use App\Filament\Resources\MergedVodGroups\Pages\ListMergedVodGroups;
use App\Models\Category;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

function makeGroup(User $user, Playlist $playlist, array $attributes): Group
{
    return Group::factory()->for($user)->for($playlist)->create(array_merge([
        'type' => 'live',
    ], $attributes));
}

it('lists only merged live groups', function () {
    $merged = makeGroup($this->user, $this->playlist, ['name' => 'Nordics', 'name_internal' => 'Nordics', 'custom' => true, 'is_merged' => true]);
    $plain = makeGroup($this->user, $this->playlist, ['name' => 'Denmark', 'name_internal' => 'Denmark']);
    $vodMerged = makeGroup($this->user, $this->playlist, ['name' => 'VOD Nordics', 'name_internal' => 'VOD Nordics', 'type' => 'vod', 'custom' => true, 'is_merged' => true]);

    Livewire::test(ListMergedGroups::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$merged])
        ->assertCanNotSeeTableRecords([$plain, $vodMerged]);
});

it('creates a merged group flagged is_merged and custom', function () {
    Livewire::test(ListMergedGroups::class)
        ->callAction('create', [
            'playlist_id' => $this->playlist->id,
            'name' => 'Nordics',
            'sort_order' => 3,
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('groups', [
        'name' => 'Nordics',
        'name_internal' => 'Nordics',
        'type' => 'live',
        'is_merged' => true,
        'custom' => true,
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);
});

it('folds selected child groups in via the manage action and releases deselected ones', function () {
    $merged = makeGroup($this->user, $this->playlist, ['name' => 'Nordics', 'name_internal' => 'Nordics', 'custom' => true, 'is_merged' => true]);
    $denmark = makeGroup($this->user, $this->playlist, ['name' => 'Denmark', 'name_internal' => 'Denmark']);
    $norway = makeGroup($this->user, $this->playlist, ['name' => 'Norway', 'name_internal' => 'Norway', 'parent_id' => $merged->id]);

    Livewire::test(ListMergedGroups::class)
        ->loadTable()
        ->callAction(TestAction::make('manageChildren')->table($merged), [
            'children' => [$denmark->id],
        ])
        ->assertHasNoActionErrors();

    expect($denmark->fresh()->parent_id)->toBe($merged->id)
        ->and($norway->fresh()->parent_id)->toBeNull();
});

it('folds groups in bulk from the Groups table without releasing existing children', function () {
    $merged = makeGroup($this->user, $this->playlist, ['name' => 'Nordics', 'name_internal' => 'Nordics', 'custom' => true, 'is_merged' => true]);
    $existing = makeGroup($this->user, $this->playlist, ['name' => 'Iceland', 'name_internal' => 'Iceland', 'parent_id' => $merged->id]);
    $denmark = makeGroup($this->user, $this->playlist, ['name' => 'Denmark', 'name_internal' => 'Denmark']);

    Livewire::test(ListGroups::class)
        ->loadTable()
        ->callTableBulkAction('addToMergedGroup', [$denmark], ['merged_group_id' => $merged->id])
        ->assertHasNoTableBulkActionErrors();

    expect($denmark->fresh()->parent_id)->toBe($merged->id)
        ->and($existing->fresh()->parent_id)->toBe($merged->id);
});

it('renders the merged VOD group and merged category list pages', function () {
    Livewire::test(ListMergedVodGroups::class)->assertOk();
    Livewire::test(ListMergedCategories::class)->assertOk();
});

it('folds series categories in bulk from the Categories table', function () {
    $merged = Category::factory()->create([
        'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id,
        'name' => 'Nordic Shows', 'name_internal' => 'Nordic Shows', 'is_merged' => true,
    ]);
    $drama = Category::factory()->create([
        'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id,
        'name' => 'DK Drama', 'name_internal' => 'DK Drama',
    ]);

    Livewire::test(ListCategories::class)
        ->loadTable()
        ->callTableBulkAction('addToMergedCategory', [$drama], ['merged_category_id' => $merged->id])
        ->assertHasNoTableBulkActionErrors();

    expect($drama->fresh()->parent_id)->toBe($merged->id);
});

it('does not offer merged groups as a channel move target', function () {
    $merged = makeGroup($this->user, $this->playlist, ['name' => 'Nordics', 'name_internal' => 'Nordics', 'custom' => true, 'is_merged' => true]);
    $plain = makeGroup($this->user, $this->playlist, ['name' => 'Denmark', 'name_internal' => 'Denmark']);

    $options = Group::query()->assignableTarget()
        ->where('playlist_id', $this->playlist->id)
        ->pluck('id');

    expect($options)->toContain($plain->id)->not->toContain($merged->id);
});
