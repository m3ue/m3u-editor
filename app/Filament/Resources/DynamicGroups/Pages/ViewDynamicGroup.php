<?php

namespace App\Filament\Resources\DynamicGroups\Pages;

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\SeriesDynamicGroups\SeriesDynamicGroupResource;
use App\Filament\Resources\VodDynamicGroups\VodDynamicGroupResource;
use App\Models\DynamicGroup;
use App\Services\DynamicGroupCacheDispatchService;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

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
 *     Dynamic Groups → Dynamic → {Group Name}   (both types)
 *
 * The root segment now points at the per-type Dynamic Groups index
 * (VodDynamicGroupResource for vod, SeriesDynamicGroupResource for
 * series) — not at the older VOD Groups / Categories pages, which
 * are unrelated surfaces for managing regular group/category rules.
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
     * Re-render every 2s so the spinning `is_cached` icon on Downloading rows
     * (in the Channels/Series relation managers) animates live while cache
     * downloads are in flight. Without polling, `animate-spin` is frozen until
     * the user manually reloads.
     */
    protected ?string $pollingInterval = '2s';

    /**
     * Default Filament title is "View {getModelLabel()}" - since the resource's
     * model label is the type-mixed "Dynamic Group", a series-type record's
     * page was titled "View Dynamic Group" instead of "View Dynamic Category".
     * Override with the same Groups/Categories vocabulary split used
     * everywhere else on this page.
     */
    public function getTitle(): string|Htmlable
    {
        return $this->isVodRecord($this->getRecord()) ? __('View Dynamic Group') : __('View Dynamic Category');
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
            Action::make('cache_now')
                ->label(__('Cache All'))
                ->icon('heroicon-o-cloud-arrow-down')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading(__('Cache Now'))
                ->modalDescription(fn (DynamicGroup $record): string => $record->type === 'series'
                    ? __('Queue downloads for this category\'s episodes now? Existing cached files are reused via fingerprint dedup; new jobs appear in the Dynamic Group Cache Activity widget.')
                    : __('Queue downloads for this group\'s content now? Existing cached files are reused via fingerprint dedup; new jobs appear in the Dynamic Group Cache Activity widget.')
                )
                ->modalSubmitActionLabel(__('Yes, cache now'))
                ->action(function (DynamicGroup $record): void {
                    $settings = app(GeneralSettings::class);
                    if (! $settings->enable_dynamic_group_cache) {
                        Notification::make()
                            ->warning()
                            ->title(__('Dynamic Group Caching is disabled'))
                            ->body(__('Enable it in Preferences → Dynamic Groups before queueing cache downloads.'))
                            ->duration(10000)
                            ->send();

                        return;
                    }

                    $result = app(DynamicGroupCacheDispatchService::class)->dispatchForGroup($record);

                    if ($result['dispatched'] > 0) {
                        $count = $result['dispatched'];
                        $title = $count === 1
                            ? __('Dispatched 1 cache job.')
                            : __('Dispatched :count cache jobs.', ['count' => $count]);
                        Notification::make()
                            ->success()
                            ->title($title)
                            ->body(__('Track progress in the Dynamic Group Cache Activity widget.'))
                            ->duration(10000)
                            ->send();
                    } else {
                        Notification::make()
                            ->warning()
                            ->title(__('No cache jobs queued'))
                            ->body($result['reason'] ?? __('Nothing eligible to cache.'))
                            ->duration(10000)
                            ->send();
                    }
                }),

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
     * (VodDynamicGroupResource / SeriesDynamicGroupResource), so
     * the back button and breadcrumb root both point there — not at
     * the older VOD Groups / Categories pages, which are unrelated
     * surfaces for managing regular group/category rules.
     */
    protected function rootIndexUrl(DynamicGroup $record): string
    {
        return $this->isVodRecord($record)
            ? VodDynamicGroupResource::getUrl('index')
            : SeriesDynamicGroupResource::getUrl('index');
    }

    /**
     * Breadcrumb label for the root segment. Both per-type
     * Dynamic Groups pages live under the same label since the
     * back button is type-agnostic — the user is back at the
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
