<?php

namespace App\Filament\Resources\PushDeviceTokens;

use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\PushDeviceTokens\Pages\ListPushDeviceTokens;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PushDeviceToken;
use App\Settings\GeneralSettings;
use BackedEnum;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class PushDeviceTokenResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;

    protected static ?string $model = PushDeviceToken::class;

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

    protected static ?string $recordTitleAttribute = 'token';

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
     * Push relay device registrations (the "Devices" tab).
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

                TextColumn::make('notifiable_type')
                    ->label(__('Playlist Type'))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'playlist', Playlist::class => 'Playlist',
                        'custom_playlist', CustomPlaylist::class => 'Custom Playlist',
                        'merged_playlist', MergedPlaylist::class => 'Merged Playlist',
                        'alias', PlaylistAlias::class => 'Playlist Alias',
                        default => class_basename($state),
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'playlist', Playlist::class => 'primary',
                        'custom_playlist', CustomPlaylist::class => 'info',
                        'merged_playlist', MergedPlaylist::class => 'warning',
                        'alias', PlaylistAlias::class => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('platform')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ios' => 'gray',
                        'android' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('token')
                    ->label(__('Token'))
                    ->formatStateUsing(fn (string $state): string => '••••'.substr($state, -6))
                    ->toggleable(),

                TextColumn::make('last_seen_at')
                    ->label(__('Last Check-in'))
                    ->since()
                    ->sortable()
                    ->color(fn (?Carbon $state): ?string => $state?->lt(now()->subDays(config('services.push_relay.stale_days', 60))) ? 'danger' : null),

                TextColumn::make('created_at')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('notifiable_type')
                    ->label(__('Playlist Type'))
                    ->options([
                        'playlist' => 'Playlist',
                        'custom_playlist' => 'Custom Playlist',
                        'merged_playlist' => 'Merged Playlist',
                        'alias' => 'Playlist Alias',
                    ]),
                SelectFilter::make('platform')
                    ->options([
                        'ios' => 'iOS',
                        'android' => 'Android',
                    ]),
                Filter::make('stale')
                    ->label(__('Stale (past prune window)'))
                    ->query(fn (Builder $query): Builder => $query->where(
                        'last_seen_at', '<', now()->subDays(config('services.push_relay.stale_days', 60))
                    )),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label(__('Revoke'))
                    ->modalHeading(__('Revoke device'))
                    ->modalDescription(__('This device will stop receiving push notifications until it re-registers with the app. This action cannot be undone.'))
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label(__('Revoke selected')),
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
