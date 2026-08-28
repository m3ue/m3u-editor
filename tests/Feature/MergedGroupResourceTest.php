<?php

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\RelationManagers\ChildCategoriesRelationManager;
use App\Filament\Resources\Categories\RelationManagers\SeriesRelationManager;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\RelationManagers\ChannelsRelationManager;
use App\Filament\Resources\Groups\RelationManagers\ChildGroupsRelationManager;
use App\Models\Category;
use App\Models\Channel;
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

function liveGroup(User $user, Playlist $playlist, array $attributes): Group
{
    return Group::factory()->for($user)->for($playlist)->create(array_merge(['type' => 'live'], $attributes));
}

it('creates a merged group via the list header action, flagged is_merged and custom', function () {
    Livewire::test(ListGroups::class)
        ->callAction('createMerged', [
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

it('shows merged groups in the same table with the Merged Group and Parent columns', function () {
    $merged = liveGroup($this->user, $this->playlist, ['name' => 'Nordics', 'name_internal' => 'Nordics', 'custom' => true, 'is_merged' => true]);
    $child = liveGroup($this->user, $this->playlist, ['name' => 'Denmark', 'name_internal' => 'Denmark', 'parent_id' => $merged->id]);

    Livewire::test(ListGroups::class)
        ->loadTable()
        ->assertTableColumnExists('is_merged')
        ->assertTableColumnExists('parent.name')
        ->assertCanSeeTableRecords([$merged, $child])
        ->assertTableColumnStateSet('parent.name', 'Nordics', $child);
});

it('reports descendant channel and children counts on a merged group row', function () {
    $merged = liveGroup($this->user, $this->playlist, ['name' => 'Nordics', 'name_internal' => 'Nordics', 'custom' => true, 'is_merged' => true]);
    $denmark = liveGroup($this->user, $this->playlist, ['name' => 'Denmark', 'name_internal' => 'Denmark', 'parent_id' => $merged->id]);
    $norway = liveGroup($this->user, $this->playlist, ['name' => 'Norway', 'name_internal' => 'Norway', 'parent_id' => $merged->id]);

    Channel::factory()->for($this->playlist)->for($denmark)->create(['user_id' => $this->user->id, 'is_vod' => false, 'enabled' => true]);
    Channel::factory()->for($this->playlist)->for($denmark)->create(['user_id' => $this->user->id, 'is_vod' => false, 'enabled' => false]);
    Channel::factory()->for($this->playlist)->for($norway)->create(['user_id' => $this->user->id, 'is_vod' => false, 'enabled' => true]);

    Livewire::test(ListGroups::class)
        ->loadTable()
        ->assertTableColumnStateSet('live_channels_count', 3, $merged)
        ->assertTableColumnStateSet('children_count', 2, $merged)
        ->assertTableColumnStateSet('children_count', null, $denmark);
});

it('sorts the table by parent name', function () {
    $zeta = liveGroup($this->user, $this->playlist, ['name' => 'Zeta', 'name_internal' => 'Zeta', 'custom' => true, 'is_merged' => true]);
    $alpha = liveGroup($this->user, $this->playlist, ['name' => 'Alpha', 'name_internal' => 'Alpha', 'custom' => true, 'is_merged' => true]);
    $underZeta = liveGroup($this->user, $this->playlist, ['name' => 'DK', 'name_internal' => 'DK', 'parent_id' => $zeta->id]);
    $underAlpha = liveGroup($this->user, $this->playlist, ['name' => 'NO', 'name_internal' => 'NO', 'parent_id' => $alpha->id]);

    Livewire::test(ListGroups::class)
        ->loadTable()
        ->sortTable('parent.name')
        ->assertCanSeeTableRecords([$underAlpha, $underZeta], inOrder: true)
        ->sortTable('parent.name', 'desc')
        ->assertCanSeeTableRecords([$underZeta, $underAlpha], inOrder: true);
});

it('filters the table to merged groups only', function () {
    $merged = liveGroup($this->user, $this->playlist, ['name' => 'Nordics', 'name_internal' => 'Nordics', 'custom' => true, 'is_merged' => true]);
    $plain = liveGroup($this->user, $this->playlist, ['name' => 'Denmark', 'name_internal' => 'Denmark']);

    Livewire::test(ListGroups::class)
        ->loadTable()
        ->filterTable('is_merged', true)
        ->assertCanSeeTableRecords([$merged])
        ->assertCanNotSeeTableRecords([$plain]);
});

it('merges and releases child groups through the row action (merged rows only)', function () {
    $merged = liveGroup($this->user, $this->playlist, ['name' => 'Nordics', 'name_internal' => 'Nordics', 'custom' => true, 'is_merged' => true]);
    $denmark = liveGroup($this->user, $this->playlist, ['name' => 'Denmark', 'name_internal' => 'Denmark']);
    $norway = liveGroup($this->user, $this->playlist, ['name' => 'Norway', 'name_internal' => 'Norway', 'parent_id' => $merged->id]);
    $plain = liveGroup($this->user, $this->playlist, ['name' => 'Sweden', 'name_internal' => 'Sweden']);

    Livewire::test(ListGroups::class)
        ->loadTable()
        ->assertActionVisible(TestAction::make('manageChildren')->table($merged))
        ->assertActionHidden(TestAction::make('manageChildren')->table($plain))
        ->callAction(TestAction::make('manageChildren')->table($merged), ['children' => [$denmark->id]])
        ->assertHasNoActionErrors();

    expect($denmark->fresh()->parent_id)->toBe($merged->id)
        ->and($norway->fresh()->parent_id)->toBeNull();
});

it('folds groups in bulk from the Groups table without releasing existing children', function () {
    $merged = liveGroup($this->user, $this->playlist, ['name' => 'Nordics', 'name_internal' => 'Nordics', 'custom' => true, 'is_merged' => true]);
    $existing = liveGroup($this->user, $this->playlist, ['name' => 'Iceland', 'name_internal' => 'Iceland', 'parent_id' => $merged->id]);
    $denmark = liveGroup($this->user, $this->playlist, ['name' => 'Denmark', 'name_internal' => 'Denmark']);

    Livewire::test(ListGroups::class)
        ->loadTable()
        ->callTableBulkAction('addToMergedGroup', [$denmark], ['merged_group_id' => $merged->id])
        ->assertHasNoTableBulkActionErrors();

    expect($denmark->fresh()->parent_id)->toBe($merged->id)
        ->and($existing->fresh()->parent_id)->toBe($merged->id);
});

it('swaps the edit-page relation manager based on is_merged', function () {
    $merged = liveGroup($this->user, $this->playlist, ['name' => 'Nordics', 'name_internal' => 'Nordics', 'custom' => true, 'is_merged' => true]);
    $plain = liveGroup($this->user, $this->playlist, ['name' => 'Denmark', 'name_internal' => 'Denmark']);

    expect(ChildGroupsRelationManager::canViewForRecord($merged, EditGroup::class))->toBeTrue()
        ->and(ChannelsRelationManager::canViewForRecord($merged, EditGroup::class))->toBeFalse()
        ->and(ChildGroupsRelationManager::canViewForRecord($plain, EditGroup::class))->toBeFalse()
        ->and(ChannelsRelationManager::canViewForRecord($plain, EditGroup::class))->toBeTrue();
});

it('renders the child-groups relation manager for a merged group with the shared Manage action', function () {
    $merged = liveGroup($this->user, $this->playlist, ['name' => 'Nordics', 'name_internal' => 'Nordics', 'custom' => true, 'is_merged' => true]);
    $denmark = liveGroup($this->user, $this->playlist, ['name' => 'Denmark', 'name_internal' => 'Denmark', 'parent_id' => $merged->id]);
    $unrelated = liveGroup($this->user, $this->playlist, ['name' => 'Sweden', 'name_internal' => 'Sweden']);

    $component = Livewire::test(ChildGroupsRelationManager::class, [
        'ownerRecord' => $merged,
        'pageClass' => EditGroup::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$denmark])
        ->assertCanNotSeeTableRecords([$unrelated])
        ->assertSeeText('Manage Groups');

    // The header action is the same one the list view uses, bound to the owner record.
    $headerAction = collect($component->instance()->getTable()->getHeaderActions())
        ->first(fn ($action) => $action->getName() === 'manageChildren');
    expect($headerAction)->not->toBeNull()
        ->and($headerAction->isVisible())->toBeTrue();
});

it('creates a merged category via the list header action', function () {
    Livewire::test(ListCategories::class)
        ->callAction('createMerged', [
            'playlist_id' => $this->playlist->id,
            'name' => 'Nordic Shows',
            'sort_order' => 2,
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('categories', [
        'name' => 'Nordic Shows',
        'is_merged' => true,
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);
});

it('merges series categories through the row action and swaps the relation manager', function () {
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
        ->callAction(TestAction::make('manageChildren')->table($merged), ['children' => [$drama->id]])
        ->assertHasNoActionErrors();

    expect($drama->fresh()->parent_id)->toBe($merged->id)
        ->and(ChildCategoriesRelationManager::canViewForRecord($merged, EditCategory::class))->toBeTrue()
        ->and(SeriesRelationManager::canViewForRecord($merged, EditCategory::class))->toBeFalse();
});
