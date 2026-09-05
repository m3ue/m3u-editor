<?php

namespace App\Filament\Resources\Bouquets;

use App\Filament\Clusters\PlaylistAliases\PlaylistAliasesCluster;
use App\Filament\Forms\Components\CustomPlaylistGroupModalSelect;
use App\Filament\Forms\Components\SourceGroupModalSelect;
use App\Filament\Resources\Bouquets\Pages\ListBouquets;
use App\Filament\Resources\CustomPlaylists\CustomPlaylistResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Traits\HasUserFiltering;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class BouquetResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = Bouquet::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $cluster = PlaylistAliasesCluster::class;

    public static function getNavigationLabel(): string
    {
        return __('Bouquets');
    }

    public static function getModelLabel(): string
    {
        return __('Playlist Bouquet');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Playlist Bouquets');
    }

    public static function getNavigationSort(): ?int
    {
        return 6;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::getForm());
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->columnSpanFull()
                ->rules(fn (Get $get, ?Bouquet $record): array => [
                    Rule::unique('bouquets', 'name')
                        ->where('user_id', auth()->id())
                        ->where(fn ($query) => $get('custom_playlist_id')
                            ? $query->where('custom_playlist_id', $get('custom_playlist_id'))
                            : $query->where('playlist_id', $get('playlist_id')))
                        ->ignore($record?->id),
                ])
                ->helperText(__('A short name for this bouquet. Unique per playlist.')),
            Forms\Components\Textarea::make('description')
                ->columnSpanFull()
                ->helperText(__('Optional description for your reference.')),

            Schemas\Components\Fieldset::make(__('Target Playlist'))
                ->columnSpanFull()
                ->schema([
                    // Same UI-only type+id pattern as the alias form (minus merged):
                    // the hidden FK fields are the persisted state.
                    Forms\Components\Select::make('target_type')
                        ->label(__('Playlist type'))
                        ->options([
                            'playlist' => __('Standard Playlist'),
                            'custom_playlist' => __('Custom Playlist'),
                        ])
                        ->default('playlist')
                        ->selectablePlaceholder(false)
                        ->required()
                        ->dehydrated(false)
                        ->live()
                        ->disabledOn('edit')
                        ->formatStateUsing(fn (?Bouquet $record): string => $record?->custom_playlist_id !== null ? 'custom_playlist' : 'playlist')
                        ->afterStateUpdated(function (Set $set): void {
                            $set('target_id', null);
                            $set('playlist_id', null);
                            $set('custom_playlist_id', null);
                            self::resetSelections($set);
                        }),
                    Forms\Components\Select::make('target_id')
                        ->label(__('Playlist'))
                        ->options(function (Get $get): array {
                            $userId = auth()->id();

                            return $get('target_type') === 'custom_playlist'
                                ? CustomPlaylist::query()->where('user_id', $userId)->orderBy('name')->pluck('name', 'id')->all()
                                : Playlist::query()->where('user_id', $userId)->orderBy('name')->pluck('name', 'id')->all();
                        })
                        ->searchable()
                        ->preload()
                        ->required()
                        ->dehydrated(false)
                        ->live()
                        ->disabledOn('edit')
                        ->formatStateUsing(fn (?Bouquet $record): ?int => $record?->custom_playlist_id ?? $record?->playlist_id)
                        ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                            $id = $state ? (int) $state : null;
                            $isCustom = $get('target_type') === 'custom_playlist';
                            $set('playlist_id', $isCustom ? null : $id);
                            $set('custom_playlist_id', $isCustom ? $id : null);
                            self::resetSelections($set);
                        })
                        ->helperText(__('The playlist cannot be changed after creation - the selected group names would not exist on another playlist. Create a new bouquet instead.')),
                    Forms\Components\Hidden::make('playlist_id'),
                    Forms\Components\Hidden::make('custom_playlist_id'),
                ]),

            Schemas\Components\Callout::make(__('Some saved entries are missing'))
                ->columnSpanFull()
                ->color('warning')
                ->visible(fn (?Bouquet $record): bool => $record !== null && $record->staleSelectionNames() !== [])
                ->description(fn (Bouquet $record): string => __('These saved entries are no longer selectable and are kept until they return or you remove them (use the clean up action on the bouquet list):')
                    .' '.implode(', ', $record->staleSelectionNames())),

            Schemas\Components\Fieldset::make(__('Live channel groups'))
                ->columnSpanFull()
                ->schema([
                    SourceGroupModalSelect::make('group_selections.selected_groups', 'live')
                        ->label(__('Live groups'))
                        ->helperText(__('Aliases using this bouquet will include live channels from these groups.')),
                    CustomPlaylistGroupModalSelect::make('group_selections.selected_groups', 'live')
                        ->label(__('Live groups'))
                        ->helperText(__('Aliases using this bouquet will include live channels from these groups.')),
                    Forms\Components\Toggle::make('auto_include_new_live')
                        ->label(__('Automatically include new live groups'))
                        ->default(false)
                        ->visible(fn (Get $get): bool => (bool) $get('playlist_id'))
                        ->helperText(__('Newly appearing live groups from the provider are automatically added to this bouquet on sync, in addition to the groups selected above.')),
                ]),

            Schemas\Components\Fieldset::make(__('VOD groups'))
                ->columnSpanFull()
                ->schema([
                    SourceGroupModalSelect::make('group_selections.selected_vod_groups', 'vod')
                        ->label(__('VOD groups'))
                        ->helperText(__('Aliases using this bouquet will include VOD channels from these groups.')),
                    CustomPlaylistGroupModalSelect::make('group_selections.selected_vod_groups', 'vod')
                        ->label(__('VOD groups'))
                        ->helperText(__('Aliases using this bouquet will include VOD channels from these groups.')),
                    Forms\Components\Toggle::make('auto_include_new_vod')
                        ->label(__('Automatically include new VOD groups'))
                        ->default(false)
                        ->visible(fn (Get $get): bool => (bool) $get('playlist_id'))
                        ->helperText(__('Newly appearing VOD groups from the provider are automatically added to this bouquet on sync, in addition to the groups selected above.')),
                ]),

            Schemas\Components\Fieldset::make(__('Series categories'))
                ->columnSpanFull()
                ->schema([
                    SourceGroupModalSelect::make('group_selections.selected_categories', 'categories')
                        ->label(__('Series categories'))
                        ->helperText(__('Aliases using this bouquet will include series from these categories.')),
                    CustomPlaylistGroupModalSelect::make('group_selections.selected_categories', 'categories')
                        ->label(__('Series categories'))
                        ->helperText(__('Aliases using this bouquet will include series from these categories.')),
                ]),
        ];
    }

    protected static function resetSelections(Set $set): void
    {
        $set('group_selections.selected_groups', []);
        $set('group_selections.selected_vod_groups', []);
        $set('group_selections.selected_categories', []);
        $set('auto_include_new_live', false);
        $set('auto_include_new_vod', false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['playlist', 'customPlaylist'])
                ->withCount('playlistAliases'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->description(fn (Bouquet $record): string => $record->description ?? '')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('target')
                    ->label(__('Playlist'))
                    ->getStateUsing(fn (Bouquet $record): string => $record->playlist_id
                        ? ($record->playlist?->name ?? 'N/A').' ('.__('Playlist').')'
                        : ($record->customPlaylist?->name ?? 'N/A').' ('.__('Custom Playlist').')')
                    ->url(fn (Bouquet $record): ?string => $record->playlist_id
                        ? ($record->playlist ? PlaylistResource::getUrl('edit', ['record' => $record->playlist_id]) : null)
                        : ($record->customPlaylist ? CustomPlaylistResource::getUrl('edit', ['record' => $record->custom_playlist_id]) : null)),
                Tables\Columns\TextColumn::make('selection_counts')
                    ->label(__('Live / VOD / Series'))
                    ->getStateUsing(fn (Bouquet $record): string => count($record->getSelectedLiveGroupNames())
                        .' / '.count($record->getSelectedVodGroupNames())
                        .' / '.count($record->getSelectedCategoryNames()))->badge(),
                Tables\Columns\TextColumn::make('playlist_aliases_count')
                    ->label(__('Aliases'))
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalDescription(function (Bouquet $record): string {
                        $names = $record->playlistAliases()->pluck('name')->all();

                        return empty($names)
                            ? __('This bouquet is not assigned to any aliases.')
                            : __('This bouquet is assigned to the following aliases; deleting it removes its groups from their filters:').' '.implode(', ', $names);
                    })
                    ->button()
                    ->size('sm')
                    ->hiddenLabel(),
                EditAction::make()->slideOver()
                    ->button()
                    ->size('sm')
                    ->hiddenLabel(),
                Action::make('clean_up_missing')
                    ->label(__('Clean up missing'))
                    ->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()
                    ->modalDescription(function (Bouquet $record): string {
                        $stale = $record->staleSelectionNames();

                        return $stale === []
                            ? __('No missing entries found - nothing will be removed.')
                            : __('Remove these entries that are no longer selectable?').' '.implode(', ', $stale);
                    })
                    ->action(function (Bouquet $record): void {
                        $record->removeStaleSelectionNames();
                        Notification::make()->success()->title(__('Missing entries removed'))->send();
                    })
                    ->button()
                    ->size('sm'),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->modalDescription(__('Assigned aliases lose these bouquets\' groups from their filters.')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBouquets::route('/'),
        ];
    }
}
