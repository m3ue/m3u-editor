<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MergedPlaylistResource\Pages;
use App\Filament\Resources\MergedPlaylistResource\RelationManagers;
use App\Forms\Components\PlaylistEpgUrl;
use App\Forms\Components\PlaylistM3uUrl;
use App\Models\MergedPlaylist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MergedPlaylistResource extends Resource
{
    protected static ?string $model = MergedPlaylist::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    protected static ?string $navigationIcon = 'heroicon-o-forward';

    protected static ?string $navigationGroup = 'Custom';

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount('enabled_channels');
            })
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('channels_count')
                    ->label('Channels')
                    ->counts('channels')
                    ->description(fn(MergedPlaylist $record): string => "Enabled: {$record->enabled_channels_count}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('enable_proxy')
                    ->label('Proxy')
                    ->icon(fn(string $state): string => match ($state) {
                        '1' => 'heroicon-o-shield-check',
                        '0' => 'heroicon-o-shield-exclamation',
                    })->color(fn(string $state): string => match ($state) {
                        '1' => 'success',
                        '0' => 'gray',
                    })->toggleable()->sortable(),
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
                    Tables\Actions\Action::make('Download M3U')
                        ->label('Download M3U')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => route('playlist.generate', ['uuid' => $record->uuid]))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('Download M3U')
                        ->label('Download EPG')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => route('epg.generate', ['uuid' => $record->uuid]))
                        ->openUrlInNewTab(),
                    Tables\Actions\DeleteAction::make(),
                ])->button()->hiddenLabel()
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PlaylistsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMergedPlaylists::route('/'),
            // 'create' => Pages\CreateMergedPlaylist::route('/create'),
            'edit' => Pages\EditMergedPlaylist::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        $schema = [
            Forms\Components\TextInput::make('name')
                ->required()
                ->columnSpan(2)
                ->helperText('Enter the name of the playlist. Internal use only.'),
            Forms\Components\Section::make('Links')
                ->description('These links are generated based on the current playlist configuration. Only enabled channels will be included.')
                ->schema([
                    PlaylistM3uUrl::make('m3u_url')
                        ->columnSpan(2)
                        ->dehydrated(false), // don't save the value in the database
                    PlaylistEpgUrl::make('epg_url')
                        ->columnSpan(2)
                        ->dehydrated(false) // don't save the value in the database
                ])->hiddenOn(['create']),
        ];
        return [
            Forms\Components\Grid::make()
                ->hiddenOn(['edit']) // hide this field on the edit form
                ->schema($schema)
                ->columns(2),
            Forms\Components\Section::make()
                ->hiddenOn(['create']) // hide this field on the create form
                ->schema($schema)
                ->columns(2),

            Forms\Components\Section::make('Output')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('auto_channel_increment')
                        ->label('Auto channel number increment')
                        ->columnSpan(1)
                        ->inline(false)
                        ->live()
                        ->default(false)
                        ->helperText('If no channel number is set, output an automatically incrementing number.'),
                    Forms\Components\Toggle::make('enable_proxy')
                        ->label('Enable Proxy')
                        ->hint(fn(Get $get): string => $get('enable_proxy') ? 'Proxied' : 'Not proxied')
                        ->hintIcon(fn(Get $get): string => !$get('enable_proxy') ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                        ->columnSpan(1)
                        ->live()
                        ->inline(false)
                        ->default(false)
                        ->helperText('When enabled, playlists urls will be proxied through m3u editor and streamed via ffmpeg.'),
                    Forms\Components\TextInput::make('channel_start')
                        ->helperText('The starting channel number.')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->hidden(fn(Get $get): bool => !$get('auto_channel_increment'))
                        ->required(),
                ]),
        ];
    }
}
