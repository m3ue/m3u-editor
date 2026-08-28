<?php

namespace App\Services;

use App\Filament\Tables\MergedCategoryChildrenTable;
use App\Filament\Tables\MergedGroupChildrenTable;
use App\Models\Category;
use App\Models\Group;
use App\Models\Playlist;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Assigns child groups/categories to a merged parent. A merged group is a pass-through
 * container: children keep their own rows untouched and simply defer their displayed
 * name/id to the parent in playlist output (see Group::effective* accessors). Nothing
 * is written to channels, so re-imports never need to reconcile anything.
 */
class MergedGroupService
{
    /**
     * Make the given groups children of $merged, and (when $detachMissing) release any
     * current child of $merged that is not in the list. Only assignable (non-merged)
     * groups of the same playlist and type are affected.
     *
     * @param  array<int|string>  $childIds
     * @return int number of groups now merged into $merged
     */
    public static function syncGroupChildren(Group $merged, array $childIds, bool $detachMissing = true): int
    {
        if (! $merged->is_merged) {
            return 0;
        }

        $childIds = self::normalizeIds($childIds);

        $eligibleIds = Group::query()
            ->assignableTarget()
            ->where('user_id', $merged->user_id)
            ->where('playlist_id', $merged->playlist_id)
            ->where('type', $merged->type)
            ->whereIn('id', $childIds)
            ->pluck('id');

        if ($detachMissing) {
            Group::query()
                ->where('parent_id', $merged->id)
                ->whereNotIn('id', $eligibleIds)
                ->update(['parent_id' => null]);
        }

        if ($eligibleIds->isNotEmpty()) {
            Group::query()
                ->whereIn('id', $eligibleIds)
                ->update(['parent_id' => $merged->id]);
        }

        return (int) Group::query()->where('parent_id', $merged->id)->count();
    }

    /**
     * Series-category equivalent of {@see syncGroupChildren()}.
     *
     * @param  array<int|string>  $childIds
     */
    public static function syncCategoryChildren(Category $merged, array $childIds, bool $detachMissing = true): int
    {
        if (! $merged->is_merged) {
            return 0;
        }

        $childIds = self::normalizeIds($childIds);

        $eligibleIds = Category::query()
            ->assignableTarget()
            ->where('user_id', $merged->user_id)
            ->where('playlist_id', $merged->playlist_id)
            ->whereIn('id', $childIds)
            ->pluck('id');

        if ($detachMissing) {
            Category::query()
                ->where('parent_id', $merged->id)
                ->whereNotIn('id', $eligibleIds)
                ->update(['parent_id' => null]);
        }

        if ($eligibleIds->isNotEmpty()) {
            Category::query()
                ->whereIn('id', $eligibleIds)
                ->update(['parent_id' => $merged->id]);
        }

        return (int) Category::query()->where('parent_id', $merged->id)->count();
    }

    /**
     * A "New Merged Group" list-header action for the live/VOD Group resources. Creates
     * a container Group (is_merged, custom) that other groups can be merged into; it then
     * appears in the same table alongside the provider groups.
     */
    public static function createMergedGroupAction(string $type): CreateAction
    {
        return CreateAction::make('createMerged')
            ->label(__('New Merged Group'))
            ->icon('heroicon-o-rectangle-group')
            ->slideOver()
            ->schema([
                Select::make('playlist_id')
                    ->label(__('Playlist'))
                    ->options(fn (): array => Playlist::query()
                        ->where('user_id', auth()->id())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->searchable(),
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255)
                    ->helperText(__('The group-title clients see for every channel in the merged groups.')),
                TextInput::make('sort_order')
                    ->label(__('Sort Order'))
                    ->numeric()
                    ->default(0),
            ])
            ->using(fn (array $data, string $model): Model => $model::create([
                ...$data,
                'user_id' => auth()->id(),
                'custom' => true,
                'is_merged' => true,
                'type' => $type,
                'name_internal' => $data['name'],
            ]))
            ->successNotification(
                Notification::make()
                    ->success()
                    ->title(__('Merged group created'))
                    ->body(__('Use "Manage Groups" on its row to merge groups into it.')),
            );
    }

    /**
     * Series-category equivalent of {@see createMergedGroupAction()}.
     */
    public static function createMergedCategoryAction(): CreateAction
    {
        return CreateAction::make('createMerged')
            ->label(__('New Merged Category'))
            ->icon('heroicon-o-rectangle-group')
            ->slideOver()
            ->schema([
                Select::make('playlist_id')
                    ->label(__('Playlist'))
                    ->options(fn (): array => Playlist::query()
                        ->where('user_id', auth()->id())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->searchable(),
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255)
                    ->helperText(__('The category clients see for every series in the merged categories.')),
                TextInput::make('sort_order')
                    ->label(__('Sort Order'))
                    ->numeric()
                    ->default(0),
            ])
            ->using(fn (array $data, string $model): Model => $model::create([
                ...$data,
                'user_id' => auth()->id(),
                'is_merged' => true,
                'name_internal' => $data['name'],
            ]))
            ->successNotification(
                Notification::make()
                    ->success()
                    ->title(__('Merged category created'))
                    ->body(__('Use "Manage Categories" on its row to merge categories into it.')),
            );
    }

    /**
     * A per-row "Manage Groups" action for the live/VOD Group resources, shown only on
     * merged group rows. Opens a slide-over ModalTableSelect for picking which groups
     * are merged into this one; deselecting a group releases it.
     */
    public static function manageChildrenAction(?Group $ownerRecord = null): Action
    {
        // Row actions inject the row as $record; relation-manager header actions do not,
        // so callers there pass the owner record in explicitly.
        $resolve = fn (?Group $injected): Group => $ownerRecord ?? $injected;

        return Action::make('manageChildren')
            ->label(__('Manage Groups'))
            ->icon('heroicon-o-squares-plus')
            ->visible(fn (?Group $record = null): bool => (bool) $resolve($record)?->is_merged)
            ->slideOver()
            ->fillForm(fn (?Group $record = null): array => [
                'children' => $resolve($record)->children()->pluck('id')->all(),
            ])
            ->schema([
                ModalTableSelect::make('children')
                    ->label(__('Groups to merge'))
                    ->tableConfiguration(MergedGroupChildrenTable::class)
                    ->multiple()
                    ->tableArguments(function (?Group $record = null) use ($resolve): array {
                        $merged = $resolve($record);

                        return [
                            'playlist_id' => $merged->playlist_id,
                            'type' => $merged->type,
                            'merged_group_id' => $merged->id,
                        ];
                    })
                    ->getOptionLabelsUsing(fn (array $values): array => Group::whereIn('id', $values)->pluck('name', 'id')->all())
                    ->selectAction(fn (Action $action) => $action
                        ->label(__('Select groups'))
                        ->modalHeading(__('Search groups'))
                        ->button()),
            ])
            ->action(function (array $data, ?Group $record = null) use ($resolve): void {
                $merged = $resolve($record);
                $count = self::syncGroupChildren($merged, $data['children'] ?? []);

                Notification::make()
                    ->success()
                    ->title(__('Merged group updated'))
                    ->body(trans_choice(':count group merged into :name|:count groups merged into :name', $count, [
                        'count' => $count,
                        'name' => $merged->name,
                    ]))
                    ->send();
            });
    }

    /**
     * Series-category equivalent of {@see manageChildrenAction()}.
     */
    public static function manageCategoryChildrenAction(?Category $ownerRecord = null): Action
    {
        $resolve = fn (?Category $injected): Category => $ownerRecord ?? $injected;

        return Action::make('manageChildren')
            ->label(__('Manage Categories'))
            ->icon('heroicon-o-squares-plus')
            ->visible(fn (?Category $record = null): bool => (bool) $resolve($record)?->is_merged)
            ->slideOver()
            ->fillForm(fn (?Category $record = null): array => [
                'children' => $resolve($record)->children()->pluck('id')->all(),
            ])
            ->schema([
                ModalTableSelect::make('children')
                    ->label(__('Categories to merge'))
                    ->tableConfiguration(MergedCategoryChildrenTable::class)
                    ->multiple()
                    ->tableArguments(function (?Category $record = null) use ($resolve): array {
                        $merged = $resolve($record);

                        return [
                            'playlist_id' => $merged->playlist_id,
                            'merged_category_id' => $merged->id,
                        ];
                    })
                    ->getOptionLabelsUsing(fn (array $values): array => Category::whereIn('id', $values)->pluck('name', 'id')->all())
                    ->selectAction(fn (Action $action) => $action
                        ->label(__('Select categories'))
                        ->modalHeading(__('Search categories'))
                        ->button()),
            ])
            ->action(function (array $data, ?Category $record = null) use ($resolve): void {
                $merged = $resolve($record);
                $count = self::syncCategoryChildren($merged, $data['children'] ?? []);

                Notification::make()
                    ->success()
                    ->title(__('Merged category updated'))
                    ->body(trans_choice(':count category merged into :name|:count categories merged into :name', $count, [
                        'count' => $count,
                        'name' => $merged->name,
                    ]))
                    ->send();
            });
    }

    /**
     * An "Add to Merged Group" table bulk action for the live/VOD Group resources.
     * Only non-merged groups sharing the chosen merged group's playlist are merged in;
     * existing children of that merged group are left in place.
     */
    public static function addToMergedGroupBulkAction(string $type): BulkAction
    {
        return BulkAction::make('addToMergedGroup')
            ->label(__('Add to Merged Group'))
            ->icon('heroicon-o-rectangle-group')
            ->schema([
                Select::make('merged_group_id')
                    ->label(__('Merged Group'))
                    ->required()
                    ->searchable()
                    ->options(fn (): array => Group::query()
                        ->where(['user_id' => auth()->id(), 'is_merged' => true, 'type' => $type])
                        ->with('playlist')
                        ->get(['id', 'name', 'playlist_id'])
                        ->mapWithKeys(fn (Group $group) => [$group->id => $group->name.' ('.$group->playlist?->name.')'])
                        ->all()),
            ])
            ->action(function (EloquentCollection $records, array $data): void {
                $merged = Group::find($data['merged_group_id']);
                if (! $merged) {
                    return;
                }

                $count = self::syncGroupChildren($merged, $records->pluck('id')->all(), detachMissing: false);

                Notification::make()
                    ->success()
                    ->title(__('Added to merged group'))
                    ->body(trans_choice(':name now merges :count group|:name now merges :count groups', $count, [
                        'name' => $merged->name,
                        'count' => $count,
                    ]))
                    ->send();
            })
            ->deselectRecordsAfterCompletion()
            ->requiresConfirmation()
            ->modalDescription(__('Groups from a different playlist than the merged group are skipped. Channels are not affected.'));
    }

    /**
     * Series-category equivalent of {@see addToMergedGroupBulkAction()}.
     */
    public static function addToMergedCategoryBulkAction(): BulkAction
    {
        return BulkAction::make('addToMergedCategory')
            ->label(__('Add to Merged Category'))
            ->icon('heroicon-o-rectangle-group')
            ->schema([
                Select::make('merged_category_id')
                    ->label(__('Merged Category'))
                    ->required()
                    ->searchable()
                    ->options(fn (): array => Category::query()
                        ->where(['user_id' => auth()->id(), 'is_merged' => true])
                        ->with('playlist')
                        ->get(['id', 'name', 'playlist_id'])
                        ->mapWithKeys(fn (Category $category) => [$category->id => $category->name.' ('.$category->playlist?->name.')'])
                        ->all()),
            ])
            ->action(function (EloquentCollection $records, array $data): void {
                $merged = Category::find($data['merged_category_id']);
                if (! $merged) {
                    return;
                }

                $count = self::syncCategoryChildren($merged, $records->pluck('id')->all(), detachMissing: false);

                Notification::make()
                    ->success()
                    ->title(__('Added to merged category'))
                    ->body(trans_choice(':name now merges :count category|:name now merges :count categories', $count, [
                        'name' => $merged->name,
                        'count' => $count,
                    ]))
                    ->send();
            })
            ->deselectRecordsAfterCompletion()
            ->requiresConfirmation()
            ->modalDescription(__('Categories from a different playlist than the merged category are skipped. Series are not affected.'));
    }

    /**
     * @param  array<int|string>  $ids
     * @return Collection<int, int>
     */
    private static function normalizeIds(array $ids): Collection
    {
        return collect($ids)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }
}
