<?php

namespace App\Filament\Resources\VodDynamicGroups\Pages;

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\VodDynamicGroups\VodDynamicGroupResource;
use App\Jobs\SyncDynamicGroups;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Services\TmdbService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * VOD-only listing surface for DynamicGroup rows.
 *
 * Sister page to `SeriesDynamicGroups\Pages\ListSeriesDynamicGroups` -
 * the per-type "Dynamic Groups" sidebar listing that this refactor
 * splits out of the VOD Groups and Series Categories footer widgets.
 * Two Filament resources are backed by the same model so each one
 * surfaces under its parent content-type section (VOD Channels / Series).
 *
 * The query is already filtered to `type = 'vod'` by
 * `VodDynamicGroupResource::getEloquentQuery()`. No per-row type
 * switch needed here.
 *
 * The row's "view" action links to the shared
 * `DynamicGroupResource::getUrl('view', ...)` route - single detail
 * page keyed by record id, breadcrumb chains through the appropriate
 * per-type listing (see `Pages\ViewDynamicGroup`).
 *
 * Cache-specific tabs, actions, and columns are intentionally absent from
 * this navigation-only surface.
 */
class ListVodDynamicGroups extends ListRecords
{
    protected static string $resource = VodDynamicGroupResource::class;

    /**
     * Header action: a 'New VOD Dynamic Group' CreateAction that opens a
     * slide-over modal with the same rule schema the Playlist form's
     * `dynamic_groups_config` Repeater uses, plus a Playlist picker
     * (prefilled with the active sub-tab when not on 'All'). On save the
     * rule is appended to that playlist's dynamic_groups_config and the
     * DynamicGroup row is materialized synchronously so the new group
     * shows in the table immediately. Redirects to the shared
     * DynamicGroupResource::view page.
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New VOD Dynamic Group'))
                ->icon('heroicon-o-plus')
                ->slideOver()
                ->modalHeading(__('New VOD Dynamic Group'))
                ->modalSubmitActionLabel(__('Create'))
                // array_merge() (not the `+` operator) re-indexes numeric
                // keys instead of letting the first array's index win the
                // collision, which silently dropped the Enabled toggle here
                // (both arrays are numerically indexed starting at 0).
                ->schema(array_merge([
                    Select::make('playlist_id')
                        ->label(__('Playlist'))
                        ->required()
                        ->options(fn (): array => Playlist::query()
                            ->where('user_id', Auth::id())
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn (): ?int => $this->activeTab !== null
                            ? (int) $this->activeTab
                            : null)
                        ->helperText(__('The Dynamic Group rule will be appended to this playlist\'s Dynamic Groups (TMDB) configuration.')),
                    // The type is fixed by which listing page this action is
                    // on, so the Content Type selector from the reused rule
                    // schema is replaced with a locked Hidden field. Keeping
                    // it visible/editable would let a user pick series-only
                    // `source` options while the closure below still forces
                    // `type` to 'vod' on submit, corrupting the saved rule.
                    Hidden::make('type')->default('vod'),
                ], array_values(array_filter(
                    PlaylistResource::getDynamicGroupRuleSchema(),
                    fn ($field): bool => $field->getName() !== 'type',
                ))))
                // Inject the implicit type INSIDE the using() closure rather
                // than via Filament's mutateFormDataBeforeCreate hook (v5
                // dropped that method). The data array is passed through;
                // the closure mutates it before the create call.
                ->using(function (array $data, string $model): DynamicGroup {
                    $data['type'] = 'vod';
                    $playlist = Playlist::findOrFail($data['playlist_id']);
                    $rule = collect($data)
                        ->only([
                            'enabled', 'type', 'source', 'name', 'tmdb_params',
                        ])
                        ->all();

                    // Append to the playlist's dynamic_groups_config (preserve
                    // existing rules, push new one at the end).
                    $config = $playlist->dynamic_groups_config ?? [];
                    $config[] = $rule;
                    $playlist->update(['dynamic_groups_config' => $config]);

                    // Materialize the DynamicGroup row + membership
                    // synchronously so the new group shows in the table
                    // immediately. Reuses the job's per-rule helper.
                    $tmdb = app(TmdbService::class);
                    $job = new SyncDynamicGroups($playlist->id);
                    $group = $job->materializeRule(
                        $playlist,
                        $data['type'],
                        $data['source'],
                        $data['name'],
                        $data['tmdb_params'] ?? [],
                        count($config) - 1,
                        $tmdb,
                    );

                    if ($group === null) {
                        // TMDB returned no ids and no pre-existing row -
                        // remove the rule we just appended so the config
                        // doesn't carry an orphan.
                        $playlist->update(['dynamic_groups_config' => array_values(array_slice($config, 0, -1))]);

                        // Halt is caught silently by Filament's action
                        // pipeline (unlike a plain exception, which
                        // surfaces as an unhandled crash), so the
                        // notification below is sent explicitly first.
                        Notification::make()
                            ->danger()
                            ->title(__('Dynamic Group not created'))
                            ->body(__('No TMDB matches for this rule and no pre-existing Dynamic Group. Rule was not saved.'))
                            ->send();

                        throw new Halt;
                    }

                    return $group;
                })
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('Dynamic Group created'))
                        ->body(__('The new group is now in the table.')),
                )
                ->successRedirectUrl(fn (DynamicGroup $record): string => DynamicGroupResource::getUrl('view', ['record' => $record]))
                ->visible(fn (): bool => VodDynamicGroupResource::shouldRegisterNavigation()),
        ];
    }

    /**
     * Per-playlist sub-tabs. Scope the Dynamic Groups view to a single
     * playlist. Keyed by `playlist_id` (matches `setupTabs()` in the
     * parent `VodGroupResource` / `CategoryResource` so the URL form
     * `?tab={id}` carries over cleanly when the View page's back
     * button comes back to this listing).
     */
    public function getTabs(): array
    {
        $base = static::getResource()::getEloquentQuery();

        // Build the per-playlist buckets via a single groupBy query (see
        // VodDynamicGroupResource::getEloquentQuery() - no withCount
        // attached so this combination is Postgres-safe). The "all" count
        // is derived from this same result instead of a second COUNT(*).
        $playlistCounts = (clone $base)
            ->selectRaw('playlist_id, count(*) as aggregate')
            ->groupBy('playlist_id')
            ->pluck('aggregate', 'playlist_id');

        $playlists = Playlist::query()
            ->whereIn('id', $playlistCounts->keys())
            ->orderBy('name')
            ->get(['id', 'name']);

        $tabs = [
            // `null` is the conventional "all" sentinel that
            // Filament's ListRecords treats as "no extra where".
            null => Tab::make(__('All Playlists'))
                ->badge($playlistCounts->sum()),
        ];
        foreach ($playlists as $playlist) {
            $tabs[(string) $playlist->id] = Tab::make($playlist->name)
                ->modifyQueryUsing(fn ($query) => $query->where('dynamic_groups.playlist_id', $playlist->id))
                ->badge($playlistCounts->get($playlist->id, 0));
        }

        return $tabs;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            // withCount('channels') attaches the channels pivot count as
            // a subquery column the TextColumn::make('channels_count')
            // reads from. Applied via modifyQueryUsing so it ONLY
            // attaches when the table renders - getTabs() runs a
            // separate groupBy('playlist_id') query against the
            // resource's getEloquentQuery() and that combination blows
            // up Postgres (subquery columns must be in GROUP BY).
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('channels'))
            ->recordUrl(fn (DynamicGroup $record): string => DynamicGroupResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('playlist.name')
                    ->label(__('Playlist'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source')
                    ->formatStateUsing(fn (DynamicGroup $record): string => DynamicGroupResource::sourceLabelFor($record->type)[$record->source] ?? $record->source)
                    ->badge(),
                IconColumn::make('enabled')
                    ->boolean(),
                TextColumn::make('channels_count')
                    ->label(__('Items'))
                    ->numeric(),
                TextColumn::make('last_synced_at')
                    ->since()
                    ->placeholder(__('Never'))
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->button()
                    ->size('sm')
                    ->hiddenLabel(),
                Action::make('view')
                    ->label(__('View'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (DynamicGroup $record): string => DynamicGroupResource::getUrl('view', ['record' => $record]))
                    ->button()
                    ->size('sm')
                    ->hiddenLabel(),
            ], RecordActionsPosition::BeforeCells)
            ->emptyStateHeading(__('No Dynamic Groups configured'))
            ->emptyStateDescription(__('Add Dynamic Groups in the Playlist form → Processing → Dynamic Groups (TMDB) section. Synced TMDB lists appear here with their current member counts.'))
            ->emptyStateIcon('heroicon-o-sparkles');
    }
}
