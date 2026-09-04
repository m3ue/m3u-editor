<?php

namespace App\Filament\Resources\DynamicGroups\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\VodGroups\VodGroupResource;
use App\Models\DynamicGroup;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only View surface for a DynamicGroup row.
 *
 * The auto-generated breadcrumb (Filament's real hook is `getBreadcrumbs()`,
 * plural — a `getHeaderBreadcrumbs()` override here previously did nothing,
 * silently) linked the resource's own plural label ("Dynamic Groups") to
 * `getIndexUrl()`, which is confusing on two counts: the label doesn't
 * distinguish VOD from Series, and the destination (Playlists) isn't where
 * anyone drilled in from. We override the real hook to chain through the
 * type-appropriate parent resource instead:
 *
 *     Groups → Dynamic → {Group Name}        (vod-type)
 *     Categories → Dynamic → {Group Name}    (series-type)
 *
 * matching this app's existing "Groups"/"Categories" vocabulary split
 * (VodGroupResource vs CategoryResource) instead of the type-mixed
 * "Dynamic Groups" wording that reads correctly for VOD but not Series.
 *
 * Only the membership relation managers stay strictly read-only. Deleting
 * the DynamicGroup row itself is allowed — see `DeleteAction` below.
 */
class ViewDynamicGroup extends ViewRecord
{
    protected static string $resource = DynamicGroupResource::class;

    /**
     * Stashed by the delete action's `->before()` hook, while `$record` is
     * still intact - Filament evaluates `->successRedirectUrl()` with the
     * `record` parameter nulled out once the row is actually gone, so a
     * closure typed `DynamicGroup $record` there throws a TypeError.
     */
    protected ?string $redirectUrlAfterDelete = null;

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        $record = $this->getRecord();

        return [
            $this->rootIndexUrl($record) => $this->rootLabel($record),
            __('Dynamic'),
            $record->name,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back_to_index')
                ->label(fn (): string => $this->isVodRecord($this->getRecord()) ? __('Back to Groups') : __('Back to Categories'))
                ->url(fn (): string => $this->rootIndexUrl($this->getRecord()))
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),

            // The row itself is a plain user-owned record - deletable even
            // though membership underneath it is computed and read-only.
            // See class docblock. Redirect to the same type-appropriate
            // index the breadcrumb/back action use, since the record (and
            // therefore this page) no longer exists after deletion.
            DeleteAction::make()
                ->before(function (DynamicGroup $record): void {
                    $this->redirectUrlAfterDelete = $this->rootIndexUrl($record);
                })
                ->successRedirectUrl(fn (): ?string => $this->redirectUrlAfterDelete),
        ];
    }

    /**
     * The Groups/Categories index URL for this record's type - the natural
     * parent list for a per-playlist VOD Group or Series Category row.
     */
    protected function rootIndexUrl(DynamicGroup $record): string
    {
        return $this->isVodRecord($record)
            ? VodGroupResource::getUrl('index')
            : CategoryResource::getUrl('index');
    }

    /**
     * Breadcrumb label for the root segment - "Groups" or "Categories"
     * matching this app's existing VodGroupResource/CategoryResource
     * vocabulary split, instead of the type-mixed "Dynamic Groups".
     */
    protected function rootLabel(DynamicGroup $record): string
    {
        return $this->isVodRecord($record) ? __('Groups') : __('Categories');
    }

    protected function isVodRecord(DynamicGroup $record): bool
    {
        return $record->type === 'vod';
    }
}
