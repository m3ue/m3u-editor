<?php

namespace App\Filament\Resources\StreamWatchSessions;

use App\Filament\Resources\StreamWatchSessions\Pages\ListStreamWatchSessions;
use App\Models\PlaylistAuth;
use App\Models\StreamWatchSession;
use App\Settings\GeneralSettings;
use Carbon\CarbonInterval;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

/**
 * Proxy > Watch History: every live channel, movie, and episode streamed
 * through m3u-proxy, with who watched it, on what, and for how long. Shown
 * when "Track watch history" is on under Settings > Proxy.
 */
class StreamWatchSessionResource extends Resource
{
    protected static ?string $model = StreamWatchSession::class;

    protected static ?string $slug = 'watch-history';

    protected static bool $isGloballySearchable = false;

    // Right after Stream Monitor (6) in the Proxy group.
    protected static ?int $navigationSort = 7;

    public static function getNavigationLabel(): string
    {
        return __('Watch History');
    }

    public static function getModelLabel(): string
    {
        return __('watch history entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Watch History');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Proxy');
    }

    public static function canAccess(): bool
    {
        return config('proxy.proxy_integration_enabled', true)
            && auth()->check()
            && auth()->user()->canUseProxy()
            && app(GeneralSettings::class)->proxy_track_watch_history;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', Auth::id());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['playlistAuth:id,name', 'viewer:id,name', 'channel', 'episode']))
            ->persistSortInSession()
            ->persistFiltersInSession()
            ->filtersTriggerAction(fn ($action) => $action->button()->label(__('Filters')))
            ->deferLoading()
            ->poll('30s')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('started_at', 'desc')
            ->columns([
                Split::make([
                    ImageColumn::make('thumbnail_url')
                        ->label(__('Poster'))
                        ->imageWidth('auto')
                        ->imageHeight('auto')
                        ->extraImgAttributes(fn (StreamWatchSession $record): array => [
                            'style' => $record->content_type === 'live'
                                ? 'max-height:3rem; max-width:8rem; border-radius:6px;'
                                : 'min-width:100px; max-width:160px; max-height:150px; border-radius:6px;',
                        ])
                        ->checkFileExistence(false)
                        ->grow(false),

                    Stack::make([
                        TextColumn::make('title')
                            ->label(__('Title'))
                            ->default(__('Unknown'))
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large)
                            ->wrap()
                            ->searchable(['title', 'episode_title', 'programme_title', 'username', 'ip_address']),

                        TextColumn::make('details')
                            ->label(__('Details'))
                            ->state(fn (StreamWatchSession $record): ?string => self::details($record))
                            ->color('gray'),

                        TextColumn::make('watched_by')
                            ->label(__('Watched By'))
                            ->state(fn (StreamWatchSession $record): string => self::watchedBy($record))
                            ->icon('heroicon-m-user')
                            ->iconColor('gray'),

                        TextColumn::make('client')
                            ->label(__('Player'))
                            ->state(fn (StreamWatchSession $record): ?string => self::client($record))
                            ->icon('heroicon-m-device-tablet')
                            ->iconColor('gray')
                            ->color('gray'),

                        TextColumn::make('flags')
                            ->label(__('Stream'))
                            ->state(fn (StreamWatchSession $record): array => self::flags($record))
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                __('Watching now') => 'success',
                                __('Transcoded') => 'warning',
                                default => 'gray',
                            }),

                        TextColumn::make('duration_seconds')
                            ->label(__('Watched For'))
                            ->state(fn (StreamWatchSession $record): string => self::watchedFor($record))
                            ->icon(fn (StreamWatchSession $record): string => $record->isOpen() ? 'heroicon-o-signal' : 'heroicon-o-clock')
                            ->iconColor(fn (StreamWatchSession $record): string => $record->isOpen() ? 'success' : 'gray')
                            ->sortable(),

                        TextColumn::make('started_at')
                            ->label(__('Started'))
                            ->dateTime()
                            ->since()
                            ->dateTimeTooltip()
                            ->prefix(__('Started').' ')
                            ->color('gray')
                            ->sortable(),
                    ])->space(1),
                ])->from('md'),
            ])
            ->filters([
                SelectFilter::make('playlist_auth_id')
                    ->label(__('Playlist Auth'))
                    ->options(fn (): array => PlaylistAuth::where('user_id', Auth::id())->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TernaryFilter::make('watching_now')
                    ->label(__('Watching now'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('ended_at'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('ended_at'),
                    ),
                TernaryFilter::make('is_transcoded')
                    ->label(__('Transcoded')),
                Filter::make('started_between')
                    ->schema([
                        DatePicker::make('from')->label(__('Started from')),
                        DatePicker::make('until')->label(__('Started until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('started_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('started_at', '<=', $date))),
            ])
            ->recordActions([
                Action::make('play')
                    ->tooltip(__('Play'))
                    ->icon('heroicon-s-play-circle')
                    ->button()
                    ->hiddenLabel()
                    ->hidden(fn (StreamWatchSession $record): bool => ! ($record->content_type === 'episode' ? $record->episode : $record->channel))
                    ->action(function (StreamWatchSession $record, $livewire): void {
                        $attributes = $record->content_type === 'episode'
                            ? $record->episode?->getFloatingPlayerAttributes()
                            : $record->channel?->getFloatingPlayerAttributes();

                        if ($attributes) {
                            $livewire->dispatch('openFloatingStream', $attributes);
                        }
                    }),
                ViewAction::make()
                    ->slideOver()
                    ->button()->hiddenLabel()->size('sm'),
                DeleteAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::AfterContent)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('No watch history yet'))
            ->emptyStateDescription(__('Streams played through the proxy appear here once they start.'))
            ->emptyStateIcon('heroicon-o-clock');
    }

    /**
     * Everything recorded for a session, for the slide-over.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('What'))
                ->columns(2)
                ->schema([
                    TextEntry::make('title')->label(__('Title'))->placeholder('-'),
                    TextEntry::make('details')->label(__('Details'))->state(fn (StreamWatchSession $record): ?string => self::details($record))->placeholder('-'),
                    TextEntry::make('programme_title')->label(__('On air when started'))->placeholder('-')->visible(fn (StreamWatchSession $record): bool => $record->content_type === 'live'),
                    TextEntry::make('playlist.name')->label(__('Playlist'))->placeholder('-'),
                    TextEntry::make('tmdb_id')->label(__('TMDB ID'))->placeholder('-'),
                    TextEntry::make('imdb_id')->label(__('IMDb ID'))->placeholder('-'),
                ]),
            Section::make(__('Who'))
                ->columns(2)
                ->schema([
                    TextEntry::make('playlistAuth.name')->label(__('Playlist Auth'))->placeholder('-'),
                    TextEntry::make('viewer.name')->label(__('Viewer'))->placeholder('-'),
                    TextEntry::make('username')->label(__('Username'))->placeholder('-')->extraAttributes(['class' => 'privacy-sensitive']),
                    TextEntry::make('ip_address')->label(__('IP Address'))->placeholder('-')->extraAttributes(['class' => 'privacy-sensitive']),
                    TextEntry::make('client_name')->label(__('Player'))->state(fn (StreamWatchSession $record): ?string => $record->clientName())->placeholder('-'),
                    TextEntry::make('user_agent')->label(__('User Agent'))->placeholder('-')->columnSpanFull(),
                ]),
            Section::make(__('When'))
                ->columns(2)
                ->schema([
                    TextEntry::make('started_at')->label(__('Started'))->dateTime(),
                    TextEntry::make('ended_at')->label(__('Ended'))->dateTime()->placeholder(__('Watching now')),
                    TextEntry::make('watched_for')->label(__('Watched For'))->state(fn (StreamWatchSession $record): string => self::watchedFor($record)),
                    TextEntry::make('bytes_served')->label(__('Data Sent'))->formatStateUsing(fn (?int $state): string => Number::fileSize((int) $state, 1))->placeholder('-'),
                    TextEntry::make('end_reason')->label(__('Ended Because'))->formatStateUsing(fn (?string $state): string => self::endReason($state))->placeholder('-'),
                    TextEntry::make('last_seen_at')->label(__('Last Seen'))->dateTime()->placeholder('-'),
                ]),
            Section::make(__('Stream'))
                ->columns(2)
                ->collapsible()
                ->schema([
                    TextEntry::make('flags')->label(__('Stream'))->state(fn (StreamWatchSession $record): array => self::flags($record))->badge()->placeholder('-'),
                    KeyValueEntry::make('media_info')->label(__('Media'))->placeholder('-'),
                    TextEntry::make('proxy_stream_id')->label(__('Proxy Stream ID')),
                    TextEntry::make('proxy_client_id')->label(__('Proxy Client ID')),
                    KeyValueEntry::make('metadata')
                        ->label(__('Proxy Metadata'))
                        ->state(fn (StreamWatchSession $record): array => collect($record->metadata ?? [])->map(fn ($value): string => is_string($value) || is_int($value) || is_float($value) ? (string) $value : json_encode($value))->all())
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStreamWatchSessions::route('/'),
        ];
    }

    /**
     * "Live · On air: News at Six" / "Movie · 1999 · tt0133093" / "Series · S02E03 · Title".
     */
    private static function details(StreamWatchSession $record): ?string
    {
        $parts = match ($record->content_type) {
            'live' => [__('Live TV'), $record->programme_title ? __('On air: :title', ['title' => $record->programme_title]) : null],
            'vod' => [__('Movie'), $record->year, $record->imdb_id],
            'episode' => [
                __('Series'),
                $record->season_number !== null && $record->episode_number !== null
                    ? sprintf('S%02dE%02d', $record->season_number, $record->episode_number)
                    : null,
                $record->episode_title,
            ],
            default => [],
        };

        return self::joinParts($parts);
    }

    private static function watchedBy(StreamWatchSession $record): string
    {
        $name = $record->playlistAuth?->name;
        $username = $record->username;

        return match (true) {
            $name !== null && $username !== null && $username !== $name => "{$name} ({$username})",
            $name !== null => $name,
            $username !== null => $username,
            default => __('Unknown'),
        };
    }

    private static function client(StreamWatchSession $record): ?string
    {
        return self::joinParts([$record->clientName(), $record->ip_address]);
    }

    /**
     * The filled parts joined with " · ", or null when none are filled.
     *
     * @param  array<int, mixed>  $parts
     */
    private static function joinParts(array $parts): ?string
    {
        $line = collect($parts)->filter(fn ($part): bool => filled($part))->implode(' · ');

        return $line !== '' ? $line : null;
    }

    /**
     * @return list<string>
     */
    private static function flags(StreamWatchSession $record): array
    {
        $media = $record->media_info ?? [];

        return array_values(array_filter([
            $record->isOpen() ? __('Watching now') : null,
            $record->is_transcoded ? __('Transcoded') : null,
            $record->is_timeshift ? __('Catch-up') : null,
            ($record->metadata['is_failover'] ?? false) ? __('Failover') : null,
            $media['resolution'] ?? null,
            isset($media['video_codec']) ? strtoupper((string) $media['video_codec']) : null,
            isset($media['audio_codec']) ? strtoupper((string) $media['audio_codec']) : null,
        ]));
    }

    private static function watchedFor(StreamWatchSession $record): string
    {
        $seconds = $record->watchedSeconds();
        if ($seconds === null) {
            return __('Length unknown');
        }

        $length = $seconds < 60
            ? __(':count s', ['count' => $seconds])
            : CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true, 'parts' => 2]);

        return $record->isOpen()
            ? __('Watching for :length', ['length' => $length])
            : __('Watched for :length', ['length' => $length]);
    }

    private static function endReason(?string $reason): string
    {
        return match ($reason) {
            'disconnected' => __('The player disconnected'),
            'stream_stopped' => __('The stream stopped'),
            'no_disconnect' => __('The proxy never reported the end'),
            default => (string) $reason,
        };
    }
}
