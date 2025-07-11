<?php

namespace App\Filament\Resources\CustomPlaylistResource\RelationManagers;

use App\Enums\ChannelLogoType;
use App\Filament\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\ChannelFailover;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Model;
use Filament\Tables\Columns\SpatieTagsColumn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Spatie\Tags\Tag;

class ChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return ChannelResource::infolist($infolist);
    }

    public function table(Table $table): Table
    {
        $ownerRecord = $this->ownerRecord;
        return $table->persistFiltersInSession()
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->recordTitleAttribute('title')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->modifyQueryUsing(function (Builder $query) {
                $query->with('tags')
                    ->withCount('failovers');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\ImageColumn::make('logo')
                    ->label('Icon')
                    ->checkFileExistence(false)
                    ->height(30)
                    ->width('auto')
                    ->getStateUsing(function ($record) {
                        if ($record->logo_type === ChannelLogoType::Channel) {
                            return $record->logo;
                        }
                        return $record->epgChannel?->icon ?? $record->logo;
                    })
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('sort')
                    ->label('Sort Order')
                    ->rules(['min:0'])
                    ->type('number')
                    ->placeholder('Sort Order')
                    ->sortable()
                    ->tooltip(fn($record) => !$record->is_custom && $record->playlist?->auto_sort ? 'Playlist auto-sort enabled; disable to change' : 'Channel sort order')
                    ->disabled(fn($record) => !$record->is_custom && $record->playlist?->auto_sort)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('failovers_count')
                    ->label('Failovers')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('is_vod')
                    ->label('Type')
                    ->formatStateUsing(fn($record) => new HtmlString(
                        $record->is_vod
                            ? '<span class="text-blue-500">VOD</span>'
                            : '<span class="text-green-500">Live</span>'
                    ))
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('has_metadata')
                    ->label('Metadata')
                    ->icon(function ($record): string {
                        if ($record->has_metadata) {
                            return 'heroicon-o-check-circle';
                        }
                        if ($record->is_vod) {
                            return 'heroicon-o-x-circle';
                        }
                        return 'heroicon-o-minus';
                    })
                    ->color(fn($record): string => $record->has_metadata ? 'success' : 'gray'),
                Tables\Columns\TextInputColumn::make('stream_id_custom')
                    ->label('ID')
                    ->rules(['min:0', 'max:255'])
                    ->tooltip(fn($record) => $record->stream_id)
                    ->placeholder(fn($record) => $record->stream_id)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('title_custom')
                    ->label('Title')
                    ->rules(['min:0', 'max:255'])
                    ->tooltip(fn($record) => $record->title)
                    ->placeholder(fn($record) => $record->title)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('name_custom')
                    ->label('Name')
                    ->rules(['min:0', 'max:255'])
                    ->tooltip(fn($record) => $record->name)
                    ->placeholder(fn($record) => $record->name)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('enabled')
                    ->toggleable()
                    ->tooltip('Toggle channel status')
                    ->sortable(),
                Tables\Columns\TextInputColumn::make('channel')
                    ->rules(['numeric', 'min:0'])
                    ->type('number')
                    ->placeholder('Channel No.')
                    ->tooltip('Channel number')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextInputColumn::make('url_custom')
                    ->label('URL')
                    ->rules(['url'])
                    ->type('url')
                    ->tooltip('Channel url')
                    ->placeholder(fn($record) => $record->url)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('shift')
                    ->rules(['numeric', 'min:0'])
                    ->type('number')
                    ->placeholder('Shift')
                    ->tooltip('Shift')
                    ->toggleable()
                    ->sortable(),
                SpatieTagsColumn::make('tags')
                    ->label('Playlist Group')
                    ->type($ownerRecord->uuid)
                    ->toggleable()->searchable(query: function (Builder $query, string $search) use ($ownerRecord): Builder {
                        return $query->whereHas('tags', function (Builder $query) use ($search, $ownerRecord) {
                            $query->where('tags.type', $ownerRecord->uuid);

                            // Cross-database compatible JSON search
                            $connection = $query->getConnection();
                            $driver = $connection->getDriverName();

                            switch ($driver) {
                                case 'pgsql':
                                    // PostgreSQL uses ->> operator for JSON
                                    $query->whereRaw('LOWER(tags.name->>\'$\') LIKE ?', ['%' . strtolower($search) . '%']);
                                    break;
                                case 'mysql':
                                    // MySQL uses JSON_EXTRACT
                                    $query->whereRaw('LOWER(JSON_EXTRACT(tags.name, "$")) LIKE ?', ['%' . strtolower($search) . '%']);
                                    break;
                                case 'sqlite':
                                    // SQLite uses json_extract
                                    $query->whereRaw('LOWER(json_extract(tags.name, "$")) LIKE ?', ['%' . strtolower($search) . '%']);
                                    break;
                                default:
                                    // Fallback - try to search the JSON as text
                                    $query->where(DB::raw('LOWER(CAST(tags.name AS TEXT))'), 'LIKE', '%' . strtolower($search) . '%');
                                    break;
                            }
                        });
                    })
                    ->sortable(query: function (Builder $query, string $direction) use ($ownerRecord): Builder {
                        $connection = $query->getConnection();
                        $driver = $connection->getDriverName();

                        // Build the ORDER BY clause based on database type
                        $orderByClause = match ($driver) {
                            'pgsql' => 'tags.name->>\'$\'',
                            'mysql' => 'JSON_EXTRACT(tags.name, "$")',
                            'sqlite' => 'json_extract(tags.name, "$")',
                            default => 'CAST(tags.name AS TEXT)'
                        };

                        return $query
                            ->leftJoin('taggables', function ($join) {
                                $join->on('channels.id', '=', 'taggables.taggable_id')
                                    ->where('taggables.taggable_type', '=', Channel::class);
                            })
                            ->leftJoin('tags', function ($join) use ($ownerRecord) {
                                $join->on('taggables.tag_id', '=', 'tags.id')
                                    ->where('tags.type', '=', $ownerRecord->uuid);
                            })
                            ->orderByRaw("{$orderByClause} {$direction}")
                            ->select('channels.*')
                            ->distinct();
                    }),
                Tables\Columns\TextColumn::make('group')
                    ->label('Default Group')
                    ->toggleable()
                    ->searchable(query: function ($query, string $search): Builder {
                        $connection = $query->getConnection();
                        $driver = $connection->getDriverName();

                        switch ($driver) {
                            case 'pgsql':
                                return $query->orWhereRaw('LOWER("group"::text) LIKE ?', ["%{$search}%"]);
                            case 'mysql':
                                return $query->orWhereRaw('LOWER(`group`) LIKE ?', ["%{$search}%"]);
                            case 'sqlite':
                                return $query->orWhereRaw('LOWER("group") LIKE ?', ["%{$search}%"]);
                            default:
                                // Fallback using Laravel's database abstraction
                                return $query->orWhere(DB::raw('LOWER(group)'), 'LIKE', "%{$search}%");
                        }
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('epgChannel.name')
                    ->label('EPG Channel')
                    ->toggleable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('epgChannel', function (Builder $query) use ($search) {
                            $query->where('epg_channels.name', 'like', "%{$search}%");
                        });
                    })
                    ->limit(40)
                    ->sortable(),
                Tables\Columns\SelectColumn::make('logo_type')
                    ->label('Preferred Icon')
                    ->options([
                        'channel' => 'Channel',
                        'epg' => 'EPG',
                    ])
                    ->sortable()
                    ->tooltip('Preferred icon source')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('lang')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('playlist.name')
                    ->numeric()
                    ->toggleable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('stream_id')
                    ->label('Default ID')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('title')
                    ->label('Default Title')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('name')
                    ->label('Default Name')
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('channels.name', 'like', "%{$search}%");
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('url')
                    ->label('Default URL')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('playlist')
                    ->relationship('playlist', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable(),
                Tables\Filters\Filter::make('enabled')
                    ->label('Channel is enabled')
                    ->toggle()
                    ->query(function ($query) {
                        return $query->where('enabled', true);
                    }),
                Tables\Filters\Filter::make('mapped')
                    ->label('EPG is mapped')
                    ->toggle()
                    ->query(function ($query) {
                        return $query->where('epg_channel_id', '!=', null);
                    }),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Create Custom Channel')
                    ->form(ChannelResource::getForm(customPlaylist: $ownerRecord))
                    ->modalHeading('New Custom Channel')
                    ->modalDescription('NOTE: Custom channels need to be associated with a Playlist or Custom Playlist.')
                    ->using(fn(array $data, string $model): Model => ChannelResource::createCustomChannel(
                        data: $data,
                        model: $model,
                    ))
                    ->slideOver(),
                Tables\Actions\AttachAction::make()
                    ->form(fn(Tables\Actions\AttachAction $action): array => [
                        $action
                            ->getRecordSelect()
                            ->getSearchResultsUsing(function (string $search) {
                                $searchLower = strtolower($search);
                                $channels = Auth::user()->channels()
                                    ->withoutEagerLoads()
                                    ->with('playlist')
                                    ->where(function ($query) use ($searchLower) {
                                        $query->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(title_custom) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(name_custom) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(stream_id) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(stream_id_custom) LIKE ?', ["%{$searchLower}%"]);
                                    })
                                    ->limit(50)
                                    ->get();

                                // Create options array
                                $options = [];
                                foreach ($channels as $channel) {
                                    $displayTitle = $channel->title_custom ?: $channel->title;
                                    $playlistName = $channel->getEffectivePlaylist()->name ?? 'Unknown';
                                    $options[$channel->id] = "{$displayTitle} [{$playlistName}]";
                                }

                                return $options;
                            })
                            ->getOptionLabelFromRecordUsing(function ($record) {
                                $displayTitle = $record->title_custom ?: $record->title;
                                $playlistName = $record->getEffectivePlaylist()->name ?? 'Unknown';
                                $options[$record->id] = "{$displayTitle} [{$playlistName}]";
                                return "{$displayTitle} [{$playlistName}]";
                            })
                    ])

                // Advanced attach when adding pivot values:
                // Tables\Actions\AttachAction::make()->form(fn(Tables\Actions\AttachAction $action): array => [
                //     $action->getRecordSelect(),
                //     Forms\Components\TextInput::make('title')
                //         ->label('Title')
                //         ->required(),
                // ]),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('process_vod')
                        ->label('Process VOD')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($record) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ProcessVodChannels(channel: $record));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Fetching VOD metadata for channel')
                                ->body('The VOD metadata fetching and processing has been started. You will be notified when it is complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->disabled(fn($record): bool => !$record->is_vod)
                        ->hidden(fn($record): bool => !$record->is_vod)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Fetch and process VOD metadata for the selected channel.')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\EditAction::make()
                        ->slideOver()
                        ->form(fn(Tables\Actions\EditAction $action): array => [
                            Forms\Components\Grid::make()
                                ->schema(ChannelResource::getForm(edit: true))
                                ->columns(2)
                        ]),
                    Tables\Actions\DetachAction::make()
                        ->color('warning'),
                    Tables\Actions\DeleteAction::make()
                        ->hidden(fn(Model $record) => !$record->is_custom)
                        ->disabled(fn(Model $record) => !$record->is_custom)
                ])->button()->hiddenLabel()->size('sm'),
                Tables\Actions\ViewAction::make()
                    ->button()->hiddenLabel()
                    ->size('sm')
                    ->slideOver(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make()->color('warning'),
                    Tables\Actions\BulkAction::make('add_to_group')
                        ->label('Add to custom group')
                        ->form([
                            Forms\Components\Select::make('group')
                                ->label('Select group')
                                ->options(
                                    Tag::query()
                                        ->where('type', $ownerRecord->uuid)
                                        ->get()
                                        ->map(fn($name) => [
                                            'id' => $name->getAttributeValue('name'),
                                            'name' => $name->getAttributeValue('name')
                                        ])->pluck('id', 'name')
                                )->required(),
                        ])
                        ->action(function (Collection $records, $data) use ($ownerRecord): void {
                            foreach ($records as $record) {
                                $record->syncTagsWithType([$data['group']], $ownerRecord->uuid);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Added to group')
                                ->body('The selected channels have been added to the custom group.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-squares-plus')
                        ->modalIcon('heroicon-o-squares-plus')
                        ->modalDescription('Add to group')
                        ->modalSubmitActionLabel('Yes, add to group'),

                    Tables\Actions\BulkAction::make('failover')
                        ->label('Add as failover')
                        ->form(function (Collection $records) {
                            $existingFailoverIds = $records->pluck('id')->toArray();
                            $initialMasterOptions = [];
                            foreach ($records as $record) {
                                $displayTitle = $record->title_custom ?: $record->title;
                                $playlistName = $record->getEffectivePlaylist()->name ?? 'Unknown';
                                $initialMasterOptions[$record->id] = "{$displayTitle} [{$playlistName}]";
                            }
                            return [
                                Forms\Components\ToggleButtons::make('master_source')
                                    ->label('Choose master from?')
                                    ->options([
                                        'selected' => 'Selected Channels',
                                        'searched' => 'Channel Search',
                                    ])
                                    ->icons([
                                        'selected' => 'heroicon-o-check',
                                        'searched' => 'heroicon-o-magnifying-glass',
                                    ])
                                    ->default('selected')
                                    ->live()
                                    ->grouped(),
                                Forms\Components\Select::make('selected_master_id')
                                    ->label('Select master channel')
                                    ->helperText('From the selected channels')
                                    ->options($initialMasterOptions)
                                    ->required()
                                    ->hidden(fn(Get $get) => $get('master_source') !== 'selected')
                                    ->searchable(),
                                Forms\Components\Select::make('master_channel_id')
                                    ->label('Search for master channel')
                                    ->searchable()
                                    ->required()
                                    ->hidden(fn(Get $get) => $get('master_source') !== 'searched')
                                    ->getSearchResultsUsing(function (string $search) use ($existingFailoverIds) {
                                        $searchLower = strtolower($search);
                                        $channels = Auth::user()->channels()
                                            ->withoutEagerLoads()
                                            ->with('playlist')
                                            ->whereNotIn('id', $existingFailoverIds)
                                            ->where(function ($query) use ($searchLower) {
                                                $query->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(title_custom) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(name_custom) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(stream_id) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(stream_id_custom) LIKE ?', ["%{$searchLower}%"]);
                                            })
                                            ->limit(50) // Keep a reasonable limit
                                            ->get();

                                        // Create options array
                                        $options = [];
                                        foreach ($channels as $channel) {
                                            $displayTitle = $channel->title_custom ?: $channel->title;
                                            $playlistName = $channel->getEffectivePlaylist()->name ?? 'Unknown';
                                            $options[$channel->id] = "{$displayTitle} [{$playlistName}]";
                                        }

                                        return $options;
                                    })
                                    ->helperText('To use as the master for the selected channel.')
                                    ->required(),
                            ];
                        })
                        ->action(function (Collection $records, array $data): void {
                            // Filter out the master channel from the records to be added as failovers
                            $masterRecordId = $data['master_source'] === 'selected'
                                ? $data['selected_master_id']
                                : $data['master_channel_id'];
                            $failoverRecords = $records->filter(function ($record) use ($masterRecordId) {
                                return (int)$record->id !== (int)$masterRecordId;
                            });

                            foreach ($failoverRecords as $record) {
                                ChannelFailover::updateOrCreate([
                                    'channel_id' => $masterRecordId,
                                    'channel_failover_id' => $record->id,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Channels as failover')
                                ->body('The selected channels have been added as failovers.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path-rounded-square')
                        ->modalIcon('heroicon-o-arrow-path-rounded-square')
                        ->modalDescription('Add the selected channel(s) to the chosen channel as failover sources.')
                        ->modalSubmitActionLabel('Add failovers now'),

                    Tables\Actions\BulkAction::make('enable')
                        ->label('Enable selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => true,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected channels enabled')
                                ->body('The selected channels have been enabled.')
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable the selected channel(s) now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    Tables\Actions\BulkAction::make('disable')
                        ->label('Disable selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => false,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected channels disabled')
                                ->body('The selected channels have been disabled.')
                                ->send();
                        })
                        ->color('danger')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected channel(s) now?')
                        ->modalSubmitActionLabel('Yes, disable now'),
                ]),
            ]);
    }
}
