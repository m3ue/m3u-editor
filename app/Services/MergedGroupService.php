<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Group;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
