<?php

namespace App\Filament\Resources\PushDeviceTokens;

use App\Events\DeviceDeregisteredEvent;
use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\PushDeviceTokens\Pages\ListPushDeviceTokens;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PushDeviceToken;
use App\Models\TvDevice;
use App\Settings\GeneralSettings;
use BackedEnum;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class PushDeviceTokenResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;

    protected static ?string $model = TvDevice::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    public static function getNavigationLabel(): string
    {
        return __('Devices');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public static function getModelLabel(): string
    {
        return __('Device');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Devices');
    }

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'device_name';

    /**
     * Admin-only resource (see canAccess()) — every registered device across
     * every user is visible here, unlike Playlist Viewers which scopes to the
     * signed-in user's own playlists.
     */
    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin()
            && (static::isPushRelayEnabled() || static::isDevicePairingEnabled());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::isPushRelayEnabled() || static::isDevicePairingEnabled();
    }

    /**
     * Push relay device registrations (the "Registered Devices" tab).
     */
    public static function isPushRelayEnabled(): bool
    {
        try {
            return (bool) (app(GeneralSettings::class)->push_relay_enabled ?? true);
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * Trakt-style device pairing (the "Device Pairing" tab) also requires
     * enhanced Xtream API output, since every M3U TV feature depends on it.
     */
    public static function isDevicePairingEnabled(): bool
    {
        try {
            $settings = app(GeneralSettings::class);

            return (bool) ($settings->device_pairing_enabled ?? true)
                && (bool) ($settings->app_output_enabled ?? true);
        } catch (\Exception $e) {
            return true;
        }
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['notifiable.user', 'pushToken']);
    }

    public static function table(Table $table): Table
    {
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->deferLoading()
            ->defaultSort('last_seen_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateIcon('heroicon-o-device-phone-mobile')
            ->columns([
                TextColumn::make('device_name')
                    ->label(__('Name'))
                    ->placeholder(__('Unknown device'))
                    ->description(fn (TvDevice $record): ?string => $record->isRevoked() ? __('Revoked') : null)
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('notifiable.user.name')
                    ->label(__('Owner'))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->orWhereHasMorph(
                            'notifiable',
                            [Playlist::class, CustomPlaylist::class, MergedPlaylist::class, PlaylistAlias::class],
                            fn (Builder $query) => $query->whereHas(
                                'user',
                                fn (Builder $query) => $query->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%'])
                            ),
                        );
                    }),

                TextColumn::make('notifiable.name')
                    ->label(__('Playlist'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('platform')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'androidtv' => 'Android TV',
                        'tvos' => 'tvOS',
                        'ios' => 'iOS',
                        'macos' => 'macOS',
                        null, '' => __('Unknown'),
                        default => ucfirst($state),
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'ios', 'macos' => 'gray',
                        'android', 'androidtv' => 'success',
                        'windows' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('app_version')
                    ->label(__('App version'))
                    ->placeholder(__('Unknown'))
                    ->badge()
                    ->color(fn (TvDevice $record): string => $record->supportsRemoteDeregister() ? 'gray' : 'warning')
                    ->toggleable(),

                IconColumn::make('pushToken')
                    ->label(__('Push'))
                    ->state(fn (TvDevice $record): bool => $record->pushToken !== null)
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('last_seen_at')
                    ->label(__('Last seen'))
                    ->since()
                    ->sortable()
                    ->color(fn (?Carbon $state): ?string => $state?->lt(now()->subDays(config('services.push_relay.stale_days', 60))) ? 'danger' : null),

                TextColumn::make('created_at')
                    ->label(__('First paired'))
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('platform')
                    ->options([
                        'android' => 'Android',
                        'androidtv' => 'Android TV',
                        'ios' => 'iOS',
                        'tvos' => 'tvOS',
                        'macos' => 'macOS',
                        'windows' => 'Windows',
                        'linux' => 'Linux',
                    ]),
                Filter::make('revoked')
                    ->label(__('Revoked'))
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('revoked_at')),
                Filter::make('stale')
                    ->label(__('Stale (past prune window)'))
                    ->query(fn (Builder $query): Builder => $query->where(
                        'last_seen_at', '<', now()->subDays(config('services.push_relay.stale_days', 60))
                    )),
            ])
            ->recordActions([
                Action::make('deregister')
                    ->label(__('Revoke'))
                    ->icon('heroicon-o-signal-slash')
                    ->color('danger')
                    ->button()->hiddenLabel()->size('sm')
                    ->requiresConfirmation()
                    ->modalHeading(__('Revoke device'))
                    ->modalDescription(__('The M3U TV app on this device will be signed out and returned to the pairing screen. The credential itself keeps working, so the device (or another) can pair again. This cannot be undone.'))
                    ->disabled(fn (TvDevice $record): bool => $record->isRevoked() || ! $record->supportsRemoteDeregister())
                    ->tooltip(fn (TvDevice $record): ?string => match (true) {
                        $record->isRevoked() => __('Already revoked'),
                        ! $record->supportsRemoteDeregister() => __('Requires M3U TV :version or newer', ['version' => TvDevice::MIN_DEREGISTER_VERSION]),
                        default => null,
                    })
                    ->action(fn (TvDevice $record) => static::revokeDevice($record)),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('deregister')
                        ->label(__('Revoke selected'))
                        ->icon('heroicon-o-signal-slash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Revoke selected devices'))
                        ->action(function (Collection $records): void {
                            $revoked = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if ($record->isRevoked() || ! $record->supportsRemoteDeregister()) {
                                    $skipped++;

                                    continue;
                                }

                                static::revokeDevice($record);
                                $revoked++;
                            }

                            Notification::make()
                                ->success()
                                ->title(__(':count devices revoked', ['count' => $revoked]))
                                ->body($skipped > 0 ? __(':count skipped (older app or already revoked)', ['count' => $skipped]) : null)
                                ->send();
                        }),
                ]),
            ]);
    }

    /**
     * Tombstone the registry row, tell the device to sign out over the socket
     * it is already subscribed to, and drop any linked push registration so it
     * stops receiving notifications immediately.
     */
    public static function revokeDevice(TvDevice $device): void
    {
        if ($device->isRevoked()) {
            return;
        }

        $event = DeviceDeregisteredEvent::forDevice($device);

        if ($event !== null) {
            event($event);
        }

        PushDeviceToken::query()
            ->where(function (Builder $query) use ($device): void {
                $query->where('device_id', $device->device_id);

                // Legacy fallback: push tokens registered before the app sent
                // `device_id` (pre-1.1.2) have it NULL, so the precise match
                // misses them and the device keeps getting push despite the
                // "signed out" copy. Sweep those by the coarser identity we do
                // have. A sibling device on the same auth + platform that also
                // predates `device_id` would be caught too; it re-registers
                // (with a `device_id`) on its next launch.
                $query->orWhere(function (Builder $legacy) use ($device): void {
                    $legacy->whereNull('device_id')
                        ->where('notifiable_type', $device->notifiable_type)
                        ->where('notifiable_id', $device->notifiable_id)
                        ->where('platform', $device->platform)
                        ->where('playlist_auth_id', $device->playlist_auth_id);
                });
            })
            ->delete();

        $device->update(['revoked_at' => now()]);
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
            'index' => ListPushDeviceTokens::route('/'),
        ];
    }
}
