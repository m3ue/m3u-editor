<?php

namespace App\Filament\Resources\DynamicGroups\Pages;

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\SeriesDynamicGroups\SeriesDynamicGroupResource;
use App\Filament\Resources\VodDynamicGroups\VodDynamicGroupResource;
use App\Models\DynamicGroup;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Read-only View surface for a DynamicGroup row.
 *
 * The auto-generated breadcrumb (Filament's real hook is `getBreadcrumbs()`,
 * plural - a `getHeaderBreadcrumbs()` override here previously did nothing,
 * silently) linked the resource's own plural label ("Dynamic Groups") to
 * `getIndexUrl()`, which is confusing on two counts: the label doesn't
 * distinguish VOD from Series, and the destination (Playlists) isn't where
 * anyone drilled in from. We override the real hook to chain through the
 * type-appropriate parent resource instead:
 *
 *     Dynamic Groups → Dynamic → {Group Name}   (both types)
 *
 * The root segment now points at the per-type Dynamic Groups index
 * (VodDynamicGroupResource for vod, SeriesDynamicGroupResource for
 * series) - not at the older VOD Groups / Categories pages, which are
 * unrelated surfaces for managing regular group/category rules.
 *
 * Only the membership relation managers stay strictly read-only. Deleting
 * the DynamicGroup row itself is allowed - see `DeleteAction` below.
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
     * Keep the detail title aligned with both per-type Dynamic Groups
     * listings. The parent resource is shared by VOD and Series records.
     */
    public function getTitle(): string|Htmlable
    {
        return __('View Dynamic Group');
    }

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
                ->label(__('Back to Dynamic Groups'))
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
     * The Dynamic Groups index URL for this record's type. The user
     * drill-in comes from the per-type Dynamic Groups listing
     * (VodDynamicGroupResource / SeriesDynamicGroupResource), so the
     * back button and breadcrumb root both point there - not at the
     * older VOD Groups / Categories pages, which are unrelated
     * surfaces for managing regular group/category rules.
     *
     * Carries the `?tab={playlist_id}` query string so the destination
     * page's `#[Url(as: 'tab')]`-backed tab state lands back on the
     * playlist the user drilled in from, instead of resetting to "All
     * Playlists".
     */
    protected function rootIndexUrl(DynamicGroup $record): string
    {
        $index = $this->isVodRecord($record)
            ? VodDynamicGroupResource::getUrl('index')
            : SeriesDynamicGroupResource::getUrl('index');

        return $index.'?'.http_build_query(['tab' => $record->playlist_id]);
    }

    /**
     * Breadcrumb label for the root segment. Both per-type
     * Dynamic Groups pages live under the same label since the
     * back button is type-agnostic - the user is back at the
     * Dynamic Groups list (whichever type they came from).
     */
    protected function rootLabel(DynamicGroup $record): string
    {
        return __('Dynamic Groups');
    }

    protected function isVodRecord(DynamicGroup $record): bool
    {
        return $record->type === 'vod';
    }
}
