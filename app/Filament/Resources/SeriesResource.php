<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SeriesResource\Pages;
use App\Filament\Resources\SeriesResource\RelationManagers;
use App\Models\Series;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SeriesResource extends Resource
{
    protected static ?string $model = Series::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'plot', 'genre', 'release_date', 'director'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?string $navigationGroup = 'Playlist';

    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table->persistFiltersInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\ImageColumn::make('cover')
                    ->square()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    //->description((fn($record) => $record->plot))
                    ->searchable(),
                Tables\Columns\ToggleColumn::make('enabled')
                    ->toggleable()
                    ->tooltip('Toggle series status')
                    ->sortable(),
                Tables\Columns\TextColumn::make('seasons_count')
                    ->label('Seasons')
                    ->counts('seasons')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('episodes_count')
                    ->label('Episodes')
                    ->counts('episodes')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('category.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('genre')
                    ->searchable(),
                Tables\Columns\TextColumn::make('youtube_trailer')
                    ->label('YouTube Trailer')
                    ->placeholder('No trailer ID set.')
                    ->url(fn($record): string => 'https://www.youtube.com/watch?v=' . $record->youtube_trailer)
                    ->openUrlInNewTab()
                    ->icon('heroicon-s-play'),
                Tables\Columns\TextColumn::make('release_date')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rating')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rating_5based')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('playlist.name')
                    ->numeric()
                    ->sortable(),
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
                //
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('process')
                        ->label('Process Series')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($record) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ProcessM3uImportSeriesEpisodes(
                                    playlistSeries: $record,
                                ));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Series is being processed')
                                ->body('You will be notified once complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process series now? This will fetch all episodes and seasons for this series.')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\DeleteAction::make()
                        ->modalIcon('heroicon-o-trash')
                        ->modalDescription('Are you sure you want to delete this series? This will delete all episodes and seasons for this series. This action cannot be undone.')
                        ->modalSubmitActionLabel('Yes, delete series'),
                ])->button()->hiddenLabel()->size('sm'),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\Action::make('process')
                        ->label('Process Selected Series')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new \App\Jobs\ProcessM3uImportSeriesEpisodes(
                                        playlistSeries: $record,
                                    ));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Series are being processed')
                                ->body('You will be notified once complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process selected series now? This will fetch all episodes and seasons for this series. This may take a while depending on the number of series selected.')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\EpisodesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeries::route('/'),
            //'create' => Pages\CreateSeries::route('/create'),
            'edit' => Pages\EditSeries::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\Section::make('Series Details')
                ->description('Edit or add the series details')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\TextInput::make('name')
                                ->disabled()
                                ->maxLength(255),
                            Forms\Components\Toggle::make('enabled')
                                ->inline(false)
                                ->required(),
                            Forms\Components\Select::make('category_id')
                                ->relationship('category', 'name'),
                            Forms\Components\TextInput::make('cover')
                                ->maxLength(255),
                            Forms\Components\Textarea::make('plot')
                                ->columnSpanFull(),
                            Forms\Components\TextInput::make('genre')
                                ->maxLength(255),
                            Forms\Components\DatePicker::make('release_date')
                                ->label('Release Date'),
                            Forms\Components\TextInput::make('rating')
                                ->maxLength(255),
                            Forms\Components\TextInput::make('rating_5based')
                                ->label('Rating (5 based)')
                                ->numeric(),
                            Forms\Components\Textarea::make('cast')
                                ->columnSpanFull(),
                            Forms\Components\TextInput::make('director')
                                ->maxLength(255),
                            Forms\Components\TextInput::make('backdrop_path'),
                            Forms\Components\TextInput::make('youtube_trailer')
                                ->label('YouTube Trailer ID')
                                ->maxLength(255),
                        ]),
                ]),
        ];
    }
}
