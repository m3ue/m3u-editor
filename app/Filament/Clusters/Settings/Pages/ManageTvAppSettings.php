<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Devices\Pages\PairDevice;
use App\Filament\Clusters\Settings\Pages\Concerns\BaseSettingsPage;
use App\Filament\Resources\TvDevices\TvDeviceResource;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PushDeviceToken;
use App\Notifications\Notification as AppNotification;
use App\Services\PushRelayService;
use App\Settings\GeneralSettings;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ManageTvAppSettings extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $slug = 'tv-app';

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return __('TV App');
    }

    public function getTitle(): string
    {
        return __('TV App');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make()
                    ->warning()
                    ->description(__('Enhanced Xtream API output is disabled. Please enable that in the "General" settings tab to use the TV app.'))
                    ->hidden(fn (): bool => (bool) (app(GeneralSettings::class)->app_output_enabled ?? true)),
                Section::make(__('TV Notification Tester'))
                    ->description(__('Send a test notification to a playlist target to verify the TV app notification system is connected and working.'))
                    ->icon('heroicon-m-bell-alert')
                    ->headerActions([
                        Action::make('send_tv_notification')
                            ->label(__('Send Notification'))
                            ->icon('heroicon-o-paper-airplane')
                            ->modalWidth('2xl')
                            ->schema([
                                Grid::make()->columns(2)->schema([
                                    Select::make('notifiable_type')
                                        ->label(__('Playlist type'))
                                        ->options([
                                            'playlist' => __('Playlist'),
                                            'custom_playlist' => __('Custom Playlist'),
                                            'merged_playlist' => __('Merged Playlist'),
                                            'alias' => __('Alias'),
                                        ])
                                        ->default('playlist')
                                        ->required()
                                        ->live(),
                                    Select::make('notifiable_id')
                                        ->label(__('Target'))
                                        ->required()
                                        ->searchable()
                                        ->options(function (Get $get): array {
                                            return match ($get('notifiable_type')) {
                                                'custom_playlist' => CustomPlaylist::where('user_id', auth()->id())->pluck('name', 'id')->all(),
                                                'merged_playlist' => MergedPlaylist::where('user_id', auth()->id())->pluck('name', 'id')->all(),
                                                'alias' => PlaylistAlias::whereHas('playlist', fn ($q) => $q->where('user_id', auth()->id()))->pluck('name', 'id')->all(),
                                                default => Playlist::where('user_id', auth()->id())->pluck('name', 'id')->all(),
                                            };
                                        }),
                                ]),
                                ToggleButtons::make('status')
                                    ->label(__('Level'))
                                    ->options([
                                        'info' => __('Info'),
                                        'success' => __('Success'),
                                        'warning' => __('Warning'),
                                        'danger' => __('Danger'),
                                    ])
                                    ->icons([
                                        'info' => 'heroicon-s-information-circle',
                                        'success' => 'heroicon-s-check-circle',
                                        'warning' => 'heroicon-s-exclamation-triangle',
                                        'danger' => 'heroicon-s-x-circle',
                                    ])
                                    ->colors([
                                        'info' => 'info',
                                        'success' => 'success',
                                        'warning' => 'warning',
                                        'danger' => 'danger',
                                    ])
                                    ->default('info')
                                    ->required()
                                    ->grouped()
                                    ->columnSpanFull(),
                                TextInput::make('title')
                                    ->label(__('Title'))
                                    ->required()
                                    ->placeholder(__('Notification title'))
                                    ->columnSpanFull(),
                                Textarea::make('body')
                                    ->label(__('Message'))
                                    ->rows(3)
                                    ->placeholder(__('Optional message body'))
                                    ->columnSpanFull(),
                                Grid::make()->columns(2)->schema([
                                    Select::make('channel')
                                        ->label(__('Channel'))
                                        ->default('general')
                                        ->required()
                                        ->searchable()
                                        ->options(function (): array {
                                            $channels = app(GeneralSettings::class)->tv_notification_channels;

                                            return collect($channels)
                                                ->filter(fn (array $c) => ! empty($c['name']))
                                                ->mapWithKeys(fn (array $c) => [
                                                    $c['name'] => $c['label'] ?: $c['name'],
                                                ])
                                                ->all();
                                        })
                                        ->helperText(__('Category tag for the notification.')),
                                    Toggle::make('admin_only')
                                        ->inline(false)
                                        ->label(__('Admin only'))
                                        ->helperText(__('When enabled, only admin-scope TV sessions will receive this notification.')),
                                ]),
                            ])
                            ->action(function (array $data): void {
                                $model = match ($data['notifiable_type']) {
                                    'custom_playlist' => CustomPlaylist::find($data['notifiable_id']),
                                    'merged_playlist' => MergedPlaylist::find($data['notifiable_id']),
                                    'alias' => PlaylistAlias::find($data['notifiable_id']),
                                    default => Playlist::find($data['notifiable_id']),
                                };

                                if (! $model) {
                                    Notification::make()
                                        ->danger()
                                        ->title(__('Target not found'))
                                        ->body(__('The selected playlist could not be found.'))
                                        ->send();

                                    return;
                                }

                                $notification = AppNotification::make()->title($data['title']);

                                if (! empty($data['body'])) {
                                    $notification->body($data['body']);
                                }

                                match ($data['status']) {
                                    'success' => $notification->success(),
                                    'warning' => $notification->warning(),
                                    'danger' => $notification->danger(),
                                    default => $notification->info(),
                                };

                                $notification->tvBroadcast($model, $data['channel'], $data['admin_only'] ?? false);

                                Notification::make()
                                    ->success()
                                    ->title(__('Notification sent'))
                                    ->body(__("Dispatched to \"{$model->name}\" on channel \"{$data['channel']}\"."))
                                    ->send();
                            }),
                        Action::make('get_tv_app')
                            ->label(__('Get the app'))
                            ->color('gray')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->url(config('dev.tv_releases_url'))
                            ->openUrlInNewTab(true),
                    ])
                    ->schema([
                        Callout::make()
                            ->info()
                            ->description(__('Use the "Send Notification" button above to dispatch a test TV notification to any playlist target.')),
                    ]),

                Section::make(__('Push Notifications (Mobile)'))
                    ->description(__('Deliver TV notifications to phone/tablet devices when the app is backgrounded or closed.'))
                    ->icon('heroicon-m-device-phone-mobile')
                    ->headerActions([
                        Action::make(__('Manage Devices'))
                            ->label(__('Manage Devices'))
                            ->icon('heroicon-o-device-phone-mobile')
                            ->url(TvDeviceResource::getUrl())
                            ->hidden(fn (Get $get): bool => ! (bool) $get('push_relay_enabled')),
                        Action::make('test_push_relay')
                            ->label(__('Send Push Notification'))
                            ->icon('heroicon-o-paper-airplane')
                            ->visible(fn (Get $get): bool => (bool) $get('push_relay_enabled') && app(PushRelayService::class)->isEnabled())
                            ->schema([
                                Select::make('device_id')
                                    ->label(__('Registered device'))
                                    ->required()
                                    ->searchable()
                                    ->options(fn (): array => PushDeviceToken::query()
                                        ->latest()
                                        ->limit(50)
                                        ->get()
                                        ->mapWithKeys(fn (PushDeviceToken $d) => [
                                            $d->id => "{$d->notifiable?->name} ({$d->platform}, ".$d->last_seen_at?->diffForHumans().')',
                                        ])
                                        ->all()),
                            ])
                            ->action(function (array $data): void {
                                $device = PushDeviceToken::find($data['device_id']);

                                if (! $device) {
                                    Notification::make()
                                        ->danger()
                                        ->title(__('Device not found'))
                                        ->send();

                                    return;
                                }

                                try {
                                    app(PushRelayService::class)->send(
                                        $device->token,
                                        $device->platform,
                                        '[TEST] m3u editor',
                                        __('This is a test push notification. Your push relay integration is working correctly.'),
                                    );

                                    Notification::make()
                                        ->success()
                                        ->title(__('Test Push Sent'))
                                        ->body(__('Check the device for the test notification.'))
                                        ->send();
                                } catch (Exception $e) {
                                    Notification::make()
                                        ->danger()
                                        ->title(__('Failed to Send Push'))
                                        ->body($e->getMessage())
                                        ->send();
                                }
                            }),
                        Action::make('view_relay_status')
                            ->label(__('View Relay Status'))
                            ->color('gray')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->url(config('services.push_relay.status_monitor_url'))
                            ->openUrlInNewTab()
                            ->hidden(fn (Get $get): bool => ! (bool) $get('push_relay_enabled')),
                    ])
                    ->schema([
                        Callout::make()
                            ->info()
                            ->description(__('The relay forwards TV notifications to Apple/Google push services so the mobile app can receive them while backgrounded or closed.')),
                        Toggle::make('push_relay_enabled')
                            ->label(__('Enable push relay'))
                            ->helperText(__('When enabled, TV notifications are also forwarded to registered mobile devices through the public relay.'))
                            ->live(),
                    ]),

                Section::make(__('Device Pairing'))
                    ->description(__('Let M3U TV request a short pairing code instead of typing a password on the remote. The admin enters the code here and picks which credential to sign the TV in with.'))
                    ->icon('heroicon-m-qr-code')
                    ->headerActions([
                        Action::make(__('Pair a Device'))
                            ->label(__('Pair a Device'))
                            ->icon('heroicon-o-qr-code')
                            ->url(PairDevice::getUrl())
                            ->hidden(fn (Get $get): bool => ! (bool) $get('device_pairing_enabled') || ! (bool) (app(GeneralSettings::class)->app_output_enabled ?? true)),
                    ])
                    ->schema([
                        Callout::make()
                            ->warning()
                            ->description(__('Device pairing requires enhanced Xtream API output, since every M3U TV feature depends on it. Enable "Enhanced output enabled" above to use pairing.'))
                            ->hidden(fn (): bool => (bool) (app(GeneralSettings::class)->app_output_enabled ?? true)),
                        Toggle::make('device_pairing_enabled')
                            ->label(__('Enable device pairing'))
                            ->helperText(__('When enabled, M3U TV can request a pairing code and the "Device Pairing" tab is available under Devices. Disabling this hides the tab and rejects any in-flight pairing requests.'))
                            ->disabled(fn (): bool => ! (bool) (app(GeneralSettings::class)->app_output_enabled ?? true))
                            ->live(),
                    ]),

                Section::make(__('Notification Channels'))
                    ->description(__('Define the notification channels available in the TV app. Users can subscribe to specific channels so they only receive relevant notifications. Channels not listed here are still usable - they appear automatically once a notification arrives on that channel.'))
                    ->icon('heroicon-m-tag')
                    ->collapsed()
                    ->schema([
                        Repeater::make('tv_notification_channels')
                            ->label(__('Default Notification Channels'))
                            ->schema([
                                Grid::make()->columns(2)->schema([
                                    TextInput::make('name')
                                        ->label(__('Channel slug'))
                                        ->required()
                                        ->regex('/^[a-z0-9_]+$/')
                                        ->placeholder(__('dvr_recording_completed'))
                                        ->helperText(__('Lowercase letters, numbers, and underscores only.')),
                                    TextInput::make('label')
                                        ->label(__('Display label'))
                                        ->placeholder(__('DVR Recording Completed'))
                                        ->helperText(__('Optional - shown in the TV app instead of the raw slug.')),
                                ]),
                            ])
                            ->addActionLabel(__('Add channel'))
                            ->reorderable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
