<?php

namespace App\Filament\GuestPanel\Resources\Vods;

use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Models\Channel;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use App\Facades\LogoFacade;
use App\Facades\PlaylistFacade;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Enums\RecordActionsPosition;

class VodResource extends Resource
{
    use HasPlaylist;

    protected static ?string $model = Channel::class;
    protected static ?string $navigationLabel = 'VOD';
    protected static ?string $slug = 'vod';

    public static function getNavigationBadge(): ?string
    {
        $playlist = PlaylistFacade::resolvePlaylistByUuid(static::getCurrentUuid());
        if ($playlist) {
            return (string) $playlist->channels()->where([
                ['enabled', true],
                ['is_vod', true]
            ])->count();
        }
        return '';
    }

    public static function getUrl(
        ?string $name = null,
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?\Illuminate\Database\Eloquent\Model $tenant = null,
        bool $shouldGuessMissingParameters = false
    ): string {
        $parameters['uuid'] = static::getCurrentUuid();

        // Default to 'index' if $name is not provided
        $routeName = static::getRouteBaseName($panel) . '.' . ($name ?? 'index');

        return route($routeName, $parameters, $isAbsolute);
    }

    public static function getEloquentQuery(): Builder
    {
        $playlist = PlaylistFacade::resolvePlaylistByUuid(static::getCurrentUuid());
        if ($playlist instanceof Playlist) {
            return parent::getEloquentQuery()
                ->with(['epgChannel', 'playlist', 'customPlaylist'])
                ->where([
                    ['enabled', true], // Only show enabled channels
                    ['is_vod', true], // Only show VOD channels
                    ['playlist_id', $playlist?->id] // Only show VOD channels from the current playlist
                ]);
        }
        if ($playlist instanceof CustomPlaylist) {
            return parent::getEloquentQuery()
                ->with(['epgChannel', 'customPlaylists']) // Eager load the customPlaylists relationship
                ->whereHas('customPlaylists', function ($query) use ($playlist) {
                    $query->where('custom_playlists.id', $playlist->id);
                })
                ->where([
                    ['enabled', true], // Only show enabled channels
                    ['is_vod', true], // Only show VOD channels
                ]);
        }
        return parent::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo')
                    ->label('Cover')
                    ->checkFileExistence(false)
                    ->size('inherit', 'inherit')
                    ->extraImgAttributes(fn($record): array => [
                        'style' => 'width:80px; height:120px;', // VOD channel style
                    ])
                    ->getStateUsing(fn($record) => LogoFacade::getChannelLogoUrl($record))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('info')
                    ->label('Info')
                    ->wrap()
                    ->getStateUsing(function ($record) {
                        $info = $record->info;
                        $title = $record->title_custom ?: $record->title;
                        $html = "<span class='fi-ta-text-item-label whitespace-normal text-sm leading-6 text-gray-950 dark:text-white'>{$title}</span>";
                        if (is_array($info)) {
                            $description = $info['description'] ?? $info['plot'] ?? '';
                            $html .= "<p class='text-sm text-gray-500 dark:text-gray-400 whitespace-normal mt-2'>{$description}</p>";
                        }
                        return new HtmlString($html);
                    })
                    ->extraAttributes(['style' => 'min-width: 350px;'])
                    ->toggleable(),
                Tables\Columns\IconColumn::make('has_metadata')
                    ->label('Metadata')
                    ->icon(function ($record): string {
                        if ($record->has_metadata) {
                            return 'heroicon-o-check-circle';
                        }
                        return 'heroicon-o-minus';
                    })
                    ->color(fn($record): string => $record->has_metadata ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('group')
                    ->label('Category')
                    ->toggleable()
                    ->badge()
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
                Tables\Columns\TextColumn::make('lang')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
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
                        return $query->orWhereRaw('LOWER(channels.name) LIKE ?', ['%' . strtolower($search) . '%']);
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
                //
            ])
            ->recordActions([
                Action::make('play')
                    ->tooltip('Play Video')
                    ->action(function ($record, $livewire) {
                        $livewire->dispatch('openFloatingStream', $record->getFloatingPlayerAttributes());
                    })
                    ->icon('heroicon-s-play-circle')
                    ->button()
                    ->hiddenLabel()
                    ->size('sm'),
                // ViewAction::make()
                //     ->button()
                //     ->icon('heroicon-s-information-circle')
                //     ->hiddenLabel()
                //     ->slideOver(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVod::route('/'),
            // 'view' => Pages\ViewVod::route('/{record}'),
        ];
    }
}
