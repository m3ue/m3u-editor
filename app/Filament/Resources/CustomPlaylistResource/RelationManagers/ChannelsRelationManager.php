<?php

namespace App\Filament\Resources\CustomPlaylistResource\RelationManagers;

use App\Enums\ChannelLogoType;
use App\Filament\Resources\ChannelResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Tables\Columns\SpatieTagsColumn;
use Illuminate\Support\Facades\DB;
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
        return $table
            ->recordTitleAttribute('title')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            // ->modifyQueryUsing(function (Builder $query) {
            //     $query->with('tags');
            // })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\ImageColumn::make('logo')
                    ->label('Icon')
                    ->checkFileExistence(false)
                    ->height(40)
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
                    ->tooltip(fn($record) => $record->playlist->auto_sort ? 'Playlist auto-sort enabled; disable to change' : 'Channel sort order')
                    ->disabled(fn($record) => $record->playlist->auto_sort)
                    ->toggleable(),
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
                    ->toggleable()
                    // ->searchable()
                    ->sortable(),
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
                    ->searchable()
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
                    ->searchable()
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
                Tables\Actions\AttachAction::make()

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
                    Tables\Actions\EditAction::make()
                        ->slideOver()
                        ->form(fn(Tables\Actions\EditAction $action): array => [
                            Forms\Components\Grid::make()
                                ->schema(ChannelResource::getForm())
                                ->columns(2)
                        ]),
                    Tables\Actions\ViewAction::make()
                        ->slideOver(),
                    Tables\Actions\DetachAction::make()
                ])->button()->hiddenLabel()->size('sm'),
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
