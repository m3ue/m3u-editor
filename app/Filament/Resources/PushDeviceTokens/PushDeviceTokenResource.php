<?php

namespace App\Filament\Resources\PushDeviceTokens;

use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\PushDeviceTokens\Pages\ListPushDeviceTokens;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\TvDevice;
use App\Settings\GeneralSettings;
use BackedEnum;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
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
     * Admin-only resource (see canAccess()) - every registered device across
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
                DeleteAction::make()
                    ->label(__('Delete'))
                    ->icon('heroicon-o-trash')
                    ->button()->hiddenLabel()->size('sm')
                    ->modalHeading(__('Delete device record'))
                    ->modalDescription(__('Removes this row from the registry only. It does not sign the device out - if the M3U TV app is still running it stays connected and will re-add itself on its next sync. Use Log out or Revoke access to sign it out.')),

                Action::make('logout')
                    ->label(__('Log out'))
                    ->icon('heroicon-o-signal-slash')
                    ->color('warning')
                    ->button()->hiddenLabel()->size('sm')
                    ->requiresConfirmation()
                    ->modalHeading(__('Log out device'))
                    ->modalDescription(__('The M3U TV app on this device will be signed out and returned to the pairing screen. The credential keeps working, so the device can pair again straight away. Use Revoke access instead to also block it from pairing again.'))
                    ->disabled(fn (TvDevice $record): bool => $record->isRevoked() || ! $record->supportsRemoteDeregister())
                    ->tooltip(fn (TvDevice $record): ?string => match (true) {
                        $record->isRevoked() => __('Device is revoked - restore access first'),
                        ! $record->supportsRemoteDeregister() => __('Requires M3U TV :version or newer', ['version' => TvDevice::MIN_DEREGISTER_VERSION]),
                        default => null,
                    })
                    ->action(fn (TvDevice $record) => $record->logOut()),

                Action::make('revoke')
                    ->label(__('Revoke access'))
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->button()->hiddenLabel()->size('sm')
                    ->requiresConfirmation()
                    ->visible(fn (TvDevice $record): bool => ! $record->isRevoked())
                    ->modalHeading(__('Revoke device access'))
                    ->modalDescription(__('The M3U TV app on this device will be signed out and blocked from pairing again with this playlist. The credential itself keeps working on other devices. Use Restore access to lift this later.'))
                    ->disabled(fn (TvDevice $record): bool => ! $record->supportsRemoteDeregister())
                    ->tooltip(fn (TvDevice $record): ?string => $record->supportsRemoteDeregister()
                        ? null
                        : __('Requires M3U TV :version or newer', ['version' => TvDevice::MIN_DEREGISTER_VERSION]))
                    ->action(fn (TvDevice $record) => $record->revokeAccess()),

                Action::make('restore')
                    ->label(__('Restore access'))
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->button()->hiddenLabel()->size('sm')
                    ->requiresConfirmation()
                    ->visible(fn (TvDevice $record): bool => $record->isRevoked())
                    ->modalHeading(__('Restore device access'))
                    ->modalDescription(__('This device will be allowed to pair again. It stays signed out until someone re-pairs it in the app.'))
                    ->action(fn (TvDevice $record) => $record->restoreAccess()),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('logout')
                        ->label(__('Log out selected'))
                        ->icon('heroicon-o-signal-slash')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading(__('Log out selected devices'))
                        ->action(function (Collection $records): void {
                            $done = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if ($record->isRevoked() || ! $record->supportsRemoteDeregister()) {
                                    $skipped++;

                                    continue;
                                }

                                $record->logOut();
                                $done++;
                            }

                            Notification::make()
                                ->success()
                                ->title(__(':count devices signed out', ['count' => $done]))
                                ->body($skipped > 0 ? __(':count skipped (older app or revoked)', ['count' => $skipped]) : null)
                                ->send();
                        }),
                    BulkAction::make('revoke')
                        ->label(__('Revoke access for selected'))
                        ->icon('heroicon-o-lock-closed')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Revoke access for selected devices'))
                        ->action(function (Collection $records): void {
                            $revoked = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if ($record->isRevoked() || ! $record->supportsRemoteDeregister()) {
                                    $skipped++;

                                    continue;
                                }

                                $record->revokeAccess();
                                $revoked++;
                            }

                            Notification::make()
                                ->success()
                                ->title(__(':count devices revoked', ['count' => $revoked]))
                                ->body($skipped > 0 ? __(':count skipped (older app or already revoked)', ['count' => $skipped]) : null)
                                ->send();
                        }),
                    BulkAction::make('restore')
                        ->label(__('Restore access for selected'))
                        ->icon('heroicon-o-lock-open')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading(__('Restore access for selected devices'))
                        ->action(function (Collection $records): void {
                            $restored = 0;

                            foreach ($records as $record) {
                                if (! $record->isRevoked()) {
                                    continue;
                                }

                                $record->restoreAccess();
                                $restored++;
                            }

                            Notification::make()
                                ->success()
                                ->title(__(':count devices restored', ['count' => $restored]))
                                ->send();
                        }),
                    DeleteBulkAction::make()
                        ->label(__('Delete selected'))
                        ->modalDescription(__('Removes the selected rows from the registry only. Devices still running the app are not signed out and will re-add themselves on their next sync.')),
                ]),
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
            'index' => ListPushDeviceTokens::route('/'),
        ];
    }
}
