<?php

namespace App\Filament\Resources\WatchHistoryIntegrations;

use App\Enums\WatchHistoryProvider;
use App\Filament\Resources\WatchHistoryIntegrations\Pages\ListWatchHistoryIntegrations;
use App\Jobs\SyncWatchHistoryIntegration;
use App\Models\PlaylistAuth;
use App\Models\WatchHistoryIntegration;
use App\Services\WatchHistory\WatchHistoryClient;
use App\Services\WatchHistory\WatchHistoryException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;

/**
 * Trakt / SIMKL accounts the server sends Playlist Auth watch progress to,
 * with on-demand import and export of existing history.
 */
class WatchHistoryIntegrationResource extends Resource
{
    protected static ?string $model = WatchHistoryIntegration::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'watch-history-integrations';

    // Just before Networks (110) in the Integrations group.
    protected static ?int $navigationSort = 105;

    public static function getNavigationLabel(): string
    {
        return __('Trakt & SIMKL');
    }

    public static function getModelLabel(): string
    {
        return __('Trakt/SIMKL account');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Trakt & SIMKL');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Integrations');
    }

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseIntegrations();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', Auth::id());
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->where('user_id', Auth::id());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Fieldset::make(__('Account'))
                    ->columns(2)
                    ->schema([
                        Select::make('provider')
                            ->label(__('Service'))
                            ->options(WatchHistoryProvider::options())
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if (blank($get('name')) && $state) {
                                    $set('name', WatchHistoryProvider::from($state)->getLabel());
                                }
                            })
                            ->disabledOn('edit'),

                        TextInput::make('name')
                            ->label(__('Display Name'))
                            ->placeholder(__('e.g., Trakt - Living Room'))
                            ->required()
                            ->maxLength(255),
                    ]),

                Select::make('playlistAuths')
                    ->label(__('Playlist Auths'))
                    ->relationship(
                        'playlistAuths',
                        'name',
                        // Filament selects DISTINCT rows for a many-to-many option list, and
                        // Postgres can't compare playlist_auths' json columns, so select only
                        // what the options need.
                        modifyQueryUsing: fn (Builder $query): Builder => $query
                            ->select(['playlist_auths.id', 'playlist_auths.name'])
                            ->where('playlist_auths.user_id', Auth::id()),
                    )
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->helperText(__('Watch progress from every viewer signed in with these Playlist Auths is sent to this account.')),

                Fieldset::make(__('Options'))
                    ->columns(1)
                    ->schema([
                        Toggle::make('enabled')
                            ->label(__('Enabled'))
                            ->helperText(__('Disable to pause this account without disconnecting it.'))
                            ->default(true),

                        Toggle::make('push_progress')
                            ->label(__('Send watch progress'))
                            ->helperText(__('Mark titles watched when they are finished, and save the position when someone stops part-way. Sent about a minute after playback stops.'))
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('Only movies and episodes with a TMDB, IMDb or TVDB id are sent. An M3U TV device signed in to the same service sends its own progress instead.')
                            )
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add Trakt / SIMKL'))
                    ->slideOver()
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'user_id' => Auth::id()])
                    // Go straight on to signing in to the new account.
                    ->after(fn (WatchHistoryIntegration $record, $livewire) => $livewire->replaceMountedAction(
                        'connect',
                        context: ['table' => true, 'recordKey' => $record->getKey()],
                    )),
            ])
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('provider')
                    ->label(__('Service'))
                    ->badge()
                    ->formatStateUsing(fn (WatchHistoryProvider $state): string => $state->getLabel())
                    ->color(fn (WatchHistoryProvider $state): string => $state->getColor())
                    ->sortable(),

                TextColumn::make('account_name')
                    ->label(__('Account'))
                    ->state(fn (WatchHistoryIntegration $record): string => $record->isConnected()
                        ? ($record->account_name ?: __('Connected'))
                        : __('Not connected'))
                    ->badge()
                    ->color(fn (WatchHistoryIntegration $record): string => $record->isConnected() ? 'success' : 'gray')
                    ->icon(fn (WatchHistoryIntegration $record): ?string => filled($record->last_error) ? 'heroicon-m-exclamation-triangle' : null)
                    ->tooltip(fn (WatchHistoryIntegration $record): ?string => $record->last_error),

                ToggleColumn::make('enabled')
                    ->label(__('Enabled'))
                    ->sortable(),

                ToggleColumn::make('push_progress')
                    ->label(__('Send Progress'))
                    ->sortable(),

                TextColumn::make('playlist_auths_count')
                    ->label(__('Playlist Auths'))
                    ->counts('playlistAuths')
                    ->badge()
                    ->sortable(),

                TextColumn::make('last_pushed_at')
                    ->label(__('Last Sent'))
                    ->since()
                    ->placeholder(__('Never'))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('last_synced_at')
                    ->label(__('Last Synced'))
                    ->since()
                    ->placeholder(__('Never'))
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->label(__('Service'))
                    ->options(WatchHistoryProvider::options()),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::connectAction(),
                    self::testConnectionAction(),
                    self::syncAction(),
                    self::disconnectAction(),
                    DeleteAction::make()
                        ->before(fn (WatchHistoryIntegration $record) => WatchHistoryClient::make($record)->revoke()),
                ])->button()->hiddenLabel()->size('sm'),
                EditAction::make()
                    ->slideOver()
                    ->button()->hiddenLabel()->size('sm'),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(fn (Collection $records) => $records->each(
                            fn (WatchHistoryIntegration $record) => WatchHistoryClient::make($record)->revoke()
                        )),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('No Trakt or SIMKL accounts'))
            ->emptyStateDescription(__('Add an account to send Playlist Auth watch progress to Trakt or SIMKL.'))
            ->emptyStateIcon('heroicon-o-check-badge');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWatchHistoryIntegrations::route('/'),
        ];
    }

    /**
     * Device sign-in: opening the modal asks the service for a code, the
     * user approves it on another device, then confirms here.
     */
    public static function connectAction(): Action
    {
        return Action::make('connect')
            ->label(fn (WatchHistoryIntegration $record): string => $record->isConnected() ? __('Reconnect') : __('Connect'))
            ->icon('heroicon-o-link')
            ->modalHeading(fn (WatchHistoryIntegration $record): string => __('Sign in to :provider', ['provider' => $record->provider->getLabel()]))
            ->modalDescription(__('Open the link on any device, sign in, and enter the code. Then come back and select "I\'ve approved it".'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('I\'ve approved it'))
            ->fillForm(function (WatchHistoryIntegration $record, Action $action): array {
                try {
                    return WatchHistoryClient::make($record)->startDeviceAuth();
                } catch (WatchHistoryException|ConnectionException $exception) {
                    Notification::make()
                        ->danger()
                        ->title(__('Could not start the sign-in'))
                        ->body($exception->getMessage())
                        ->send();

                    $action->cancel();

                    return [];
                }
            })
            ->schema([
                TextInput::make('user_code')
                    ->label(__('Code'))
                    ->readOnly()
                    // Filament's copyable() needs the Clipboard API, which browsers only
                    // allow over HTTPS; this falls back to execCommand on plain HTTP.
                    ->hint(fn (Get $get): ?View => filled($get('user_code'))
                        ? view('components.copy-to-clipboard', ['text' => $get('user_code'), 'position' => 'left'])
                        : null)
                    ->extraInputAttributes(['class' => 'font-mono text-2xl tracking-widest text-center']),
                TextInput::make('verification_url')
                    ->label(__('Link'))
                    ->readOnly()
                    // Filament's copyable() needs the Clipboard API, which browsers only
                    // allow over HTTPS; this falls back to execCommand on plain HTTP.
                    ->hint(fn (Get $get): ?View => filled($get('verification_url'))
                        ? view('components.copy-to-clipboard', ['text' => $get('verification_url'), 'position' => 'left'])
                        : null)
                    ->suffixAction(
                        Action::make('openVerificationUrl')
                            ->icon('heroicon-m-arrow-top-right-on-square')
                            ->url(fn (Get $get): ?string => $get('verification_url'), shouldOpenInNewTab: true)
                    ),
                Hidden::make('device_code'),
                Hidden::make('code_verifier'),
            ])
            ->action(function (WatchHistoryIntegration $record, array $data, Action $action): void {
                try {
                    $status = WatchHistoryClient::make($record)->pollDeviceAuth((string) $data['device_code'], (string) $data['code_verifier']);
                } catch (WatchHistoryException|ConnectionException $exception) {
                    Notification::make()->danger()->title(__('Sign-in failed'))->body($exception->getMessage())->send();
                    $action->halt();

                    return;
                }

                match ($status) {
                    'connected' => Notification::make()
                        ->success()
                        ->title(__('Connected to :provider', ['provider' => $record->provider->getLabel()]))
                        ->body($record->refresh()->account_name ? __('Signed in as :account.', ['account' => $record->account_name]) : null)
                        ->send(),
                    'pending', 'slow_down' => Notification::make()
                        ->warning()
                        ->title(__('Not approved yet'))
                        ->body(__('Enter the code at the link, then try again.'))
                        ->send(),
                    default => Notification::make()
                        ->danger()
                        ->title(__('The code expired or was declined'))
                        ->body(__('Select Connect again for a new code.'))
                        ->send(),
                };

                if (in_array($status, ['pending', 'slow_down'], true)) {
                    $action->halt();
                }
            });
    }

    /**
     * Check the saved sign-in still works, e.g. after it was revoked on the
     * service's side. A rejected sign-in marks the account Not connected so
     * Connect can sign in again.
     */
    public static function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label(__('Test Connection'))
            ->icon('heroicon-o-signal')
            ->visible(fn (WatchHistoryIntegration $record): bool => $record->isConnected())
            ->action(function (WatchHistoryIntegration $record): void {
                $provider = $record->provider->getLabel();

                try {
                    $account = WatchHistoryClient::make($record)->testConnection();
                } catch (WatchHistoryException $exception) {
                    Notification::make()
                        ->danger()
                        ->title($exception->signedOut
                            ? __(':provider sign-in is no longer valid', ['provider' => $provider])
                            : __('Could not reach :provider', ['provider' => $provider]))
                        ->body($exception->signedOut
                            ? __('Select Connect to sign in again.')
                            : $exception->getMessage())
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('Connection Successful'))
                    ->body(__('Signed in to :provider as :account.', ['provider' => $provider, 'account' => $account]))
                    ->send();
            });
    }

    public static function syncAction(): Action
    {
        return Action::make('sync')
            ->label(__('Import / Export'))
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn (WatchHistoryIntegration $record): bool => $record->isConnected())
            ->modalHeading(fn (WatchHistoryIntegration $record): string => __('Sync with :provider', ['provider' => $record->provider->getLabel()]))
            ->modalDescription(__('Runs in the background; you get a notification when it finishes. Only titles in the library with a TMDB, IMDb or TVDB id are matched.'))
            ->modalSubmitActionLabel(__('Start'))
            ->fillForm(fn (WatchHistoryIntegration $record): array => [
                'playlist_auth_id' => $record->playlistAuths()->count() === 1 ? $record->playlistAuths()->value('playlist_auths.id') : null,
                'mode' => 'import',
            ])
            ->schema([
                Select::make('playlist_auth_id')
                    ->label(__('Playlist Auth'))
                    ->options(fn (WatchHistoryIntegration $record): array => $record->playlistAuths()->pluck('playlist_auths.name', 'playlist_auths.id')->all())
                    ->helperText(__('Only Playlist Auths attached to this account are listed.'))
                    ->required()
                    ->native(false),
                Radio::make('mode')
                    ->label(__('What to do'))
                    ->options([
                        'import' => __('Import from the account'),
                        'export' => __('Export to the account'),
                        'sync' => __('Import, then export'),
                        'replace' => __('Replace local progress with the account\'s'),
                    ])
                    ->descriptions([
                        'import' => __('Adds watched titles and resume points. Local progress that is newer is kept.'),
                        'export' => __('Marks titles watched locally as watched on the account. Titles it already has are skipped. Resume points are not exported.'),
                        'sync' => __('Both of the above, so each side ends up with the other\'s watched titles.'),
                        'replace' => __('Deletes this Playlist Auth\'s movie and episode progress, then imports. This cannot be undone.'),
                    ])
                    ->required(),
            ])
            ->action(function (WatchHistoryIntegration $record, array $data): void {
                $playlistAuth = $record->playlistAuths()->whereKey($data['playlist_auth_id'])->first();
                if (! $playlistAuth instanceof PlaylistAuth) {
                    return;
                }

                SyncWatchHistoryIntegration::dispatch($record->id, $playlistAuth->id, $data['mode']);

                Notification::make()
                    ->success()
                    ->title(__('Sync started'))
                    ->body(__('You will get a notification when it finishes.'))
                    ->send();
            });
    }

    public static function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label(__('Disconnect'))
            ->icon('heroicon-o-link-slash')
            ->color('danger')
            ->visible(fn (WatchHistoryIntegration $record): bool => $record->isConnected())
            ->requiresConfirmation()
            ->modalDescription(fn (WatchHistoryIntegration $record): string => __('Signs out of :provider and stops sending progress. The Playlist Auths stay attached so you can reconnect later.', ['provider' => $record->provider->getLabel()]))
            ->action(function (WatchHistoryIntegration $record): void {
                WatchHistoryClient::make($record)->revoke();
                $record->disconnect();

                Notification::make()->success()->title(__('Disconnected'))->send();
            });
    }
}
