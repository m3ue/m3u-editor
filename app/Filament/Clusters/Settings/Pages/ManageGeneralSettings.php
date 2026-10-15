<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\Pages\Concerns\BaseSettingsPage;
use App\Rules\ValidDateFormat;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class ManageGeneralSettings extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-8-tooth';

    protected static ?string $slug = 'general';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('General');
    }

    public function getTitle(): string
    {
        return __('General');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_websocket')
                ->label(__('Test WebSocket'))
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->modalWidth('md')
                ->schema([
                    TextInput::make('message')
                        ->label(__('Message'))
                        ->required()
                        ->default('Testing WebSocket connection')
                        ->helperText(__('This message will be sent to the WebSocket server and displayed as a pop-up notification. If you do not see a notification shortly after sending, there is likely an issue with your WebSocket configuration.')),
                ])
                ->action(function (array $data): void {
                    Notification::make()
                        ->success()
                        ->title(__('WebSocket Connection Test'))
                        ->body($data['message'])
                        ->persistent()
                        ->broadcast(auth()->user());
                }),
        ];
    }

    /** Preset date format strings available in the select. */
    private const DATE_FORMAT_PRESETS = [
        'Y-m-d H:i:s',
        'd/m/Y H:i',
        'D, d M Y H:i:s',
        'M j, Y g:i A',
        'g:i A m/d/Y',
    ];

    /**
     * Populate virtual form fields (date_format_preset / date_format_custom)
     * from the stored date_format setting value before the form is filled.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $dateFormat = $data['date_format'] ?? null;

        if ($dateFormat && ! in_array($dateFormat, self::DATE_FORMAT_PRESETS, true)) {
            $data['date_format_preset'] = '__custom__';
            $data['date_format_custom'] = $dateFormat;
        } else {
            $data['date_format_preset'] = $dateFormat ?? 'Y-m-d H:i:s';
            $data['date_format_custom'] = null;
        }

        return $data;
    }

    /**
     * Resolve the actual format string from the virtual fields before saving
     * and strip transient keys that do not exist on GeneralSettings.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $preset = $data['date_format_preset'] ?? 'Y-m-d H:i:s';

        if ($preset === '__custom__') {
            $data['date_format'] = ! empty($data['date_format_custom'])
                ? $data['date_format_custom']
                : 'Y-m-d H:i:s';
        } else {
            $data['date_format'] = in_array($preset, self::DATE_FORMAT_PRESETS, true)
                ? $preset
                : 'Y-m-d H:i:s';
        }

        // Remove transient fields that are not in GeneralSettings
        unset($data['date_format_preset'], $data['date_format_custom']);

        return $data;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Layout & Display Options'))
                    ->schema([
                        Grid::make()
                            ->columnSpanFull()
                            ->columns(4)
                            ->schema([
                                Toggle::make('show_breadcrumbs')
                                    ->label(__('Show breadcrumbs'))
                                    ->helperText(__('Show breadcrumbs under the page titles')),
                                Toggle::make('show_queue_indicator')
                                    ->label(__('Show queue indicator'))
                                    ->helperText(__('Show the live queue status indicator in the top navigation bar')),
                                Toggle::make('output_wan_address')
                                    ->label(__('Output WAN address in menu'))
                                    ->helperText(__('When enabled, the application will output the WAN address of the server m3u-editor is currently running on.'))
                                    ->default(function () {
                                        return config('dev.show_wan_details') !== null
                                            ? (bool) config('dev.show_wan_details')
                                            : false;
                                    })
                                    ->afterStateHydrated(function (Toggle $component, $state) {
                                        if (config('dev.show_wan_details') !== null) {
                                            $component->state((bool) config('dev.show_wan_details'));
                                        }
                                    })->disabled(fn () => config('dev.show_wan_details') !== null)
                                    ->hint(fn () => config('dev.show_wan_details') !== null ? __('Already set by environment variable!') : null)
                                    ->dehydrated(fn () => config('dev.show_wan_details') === null),
                                Toggle::make('suppress_success_notifications')
                                    ->label(__('Suppress success notifications'))
                                    ->hintIcon(
                                        'heroicon-m-question-mark-circle',
                                        tooltip: __('When enabled, success and informational notifications from background tasks (e.g. sync started or completed successfully) will be hidden. Errors and warnings will still be shown regardless of this setting.')
                                    )
                                    ->helperText(__('Hide success and informational notifications from background tasks (errors and warnings are always shown).')),
                            ]),
                        Grid::make()
                            ->columnSpanFull()
                            ->columns(2)
                            ->schema([
                                Select::make('navigation_position')
                                    ->label(__('Navigation position'))
                                    ->helperText(__('Choose the position of primary navigation'))
                                    ->options([
                                        'left' => 'Left',
                                        'top' => 'Top',
                                    ]),
                                Select::make('content_width')
                                    ->label(__('Max width of the page content'))
                                    ->options([
                                        Width::ScreenMedium->value => 'Medium',
                                        Width::ScreenLarge->value => 'Large',
                                        Width::ScreenExtraLarge->value => 'XL',
                                        Width::ScreenTwoExtraLarge->value => '2XL',
                                        Width::Full->value => 'Full',
                                    ]),
                            ]),
                        Grid::make()
                            ->columnSpanFull()
                            ->columns(2)
                            ->schema([
                                TextInput::make('app_timezone')
                                    ->label(__('Application Timezone'))
                                    ->placeholder(__('UTC'))
                                    ->helperText(__('Override the application timezone. Leave empty to use the server default (UTC). Takes effect for all date/time output throughout the app.'))
                                    ->disabled(fn () => ! empty(config('dev.timezone')))
                                    ->hint(fn () => ! empty(config('dev.timezone')) ? __('Already set by environment variable!') : null)
                                    ->dehydrated(fn () => empty(config('dev.timezone')))
                                    ->afterStateHydrated(function (TextInput $component, $state) {
                                        if (! empty(config('dev.timezone'))) {
                                            $component->state(config('dev.timezone'));
                                        }
                                    })
                                    ->hintAction(
                                        Action::make('view_timezones')
                                            ->label(__('Accepted Values'))
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('https://www.php.net/manual/en/timezones.php')
                                            ->openUrlInNewTab(true)
                                    ),
                                Select::make('date_format_preset')
                                    ->label(__('Date Format'))
                                    ->options([
                                        'Y-m-d H:i:s' => 'Default - '.date('Y-m-d H:i:s', mktime(14, 30, 0, 1, 15, 2024)),
                                        'd/m/Y H:i' => 'Short - '.date('d/m/Y H:i', mktime(14, 30, 0, 1, 15, 2024)),
                                        'D, d M Y H:i:s' => 'Long - '.date('D, d M Y H:i:s', mktime(14, 30, 0, 1, 15, 2024)),
                                        'M j, Y g:i A' => 'Human Readable - '.date('M j, Y g:i A', mktime(14, 30, 0, 1, 15, 2024)),
                                        'g:i A m/d/Y' => '12-Hour AM/PM - '.date('g:i A m/d/Y', mktime(14, 30, 0, 1, 15, 2024)),
                                        '__custom__' => 'Custom...',
                                    ])
                                    ->default('Y-m-d H:i:s')
                                    ->live()
                                    ->helperText(__('Format applied to dates throughout the application (e.g. next sync, last synced).')),
                            ]),
                        Grid::make()
                            ->columnSpanFull()
                            ->columns(2)
                            ->schema([
                                TextInput::make('date_format_custom')
                                    ->label(__('Custom Date Format String'))
                                    ->placeholder(__('e.g. d/m/Y H:i:s'))
                                    ->live(debounce: 500)
                                    ->rules([new ValidDateFormat])
                                    ->helperText(function (Get $get): string {
                                        $fmt = $get('date_format_custom');

                                        if (! $fmt) {
                                            return 'Enter a PHP date format string. See the link above for accepted characters.';
                                        }

                                        try {
                                            return 'Preview: '.date($fmt, mktime(14, 30, 0, 1, 15, 2024));
                                        } catch (\Throwable) {
                                            return 'Invalid format string.';
                                        }
                                    })
                                    ->hintAction(
                                        Action::make('view_date_formats')
                                            ->label(__('PHP Date Formats'))
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('https://www.php.net/manual/en/datetime.format.php')
                                            ->openUrlInNewTab(true)
                                    )
                                    ->visible(fn (Get $get): bool => $get('date_format_preset') === '__custom__'),
                            ]),

                    ]),
                Section::make(__('Allowed Playlist Domains'))
                    ->description(__('Restrict playlist URLs to specific domains. Leave empty to allow all domains.'))
                    ->schema([
                        TagsInput::make('allowed_urls')
                            ->label(__('Allowed domains'))
                            ->columnSpanFull()
                            ->placeholder(fn () => config('dev.allowed_playlist_domains') ? null : '*.example.com*')
                            ->helperText(__('List of allowed domains (supports wildcards, e.g. *.example.com*). Press [tab] or [return] to add item. When set, playlist URLs must match one of these patterns.'))
                            ->disabled(fn () => ! empty(config('dev.allowed_playlist_domains')))
                            ->hint(fn () => ! empty(config('dev.allowed_playlist_domains')) ? __('Already set by environment variable!') : null)
                            ->default(fn () => ! empty(config('dev.allowed_playlist_domains'))
                                ? array_map('trim', explode(',', config('dev.allowed_playlist_domains')))
                                : [])
                            ->afterStateHydrated(function (TagsInput $component, $state) {
                                if (! empty(config('dev.allowed_playlist_domains'))) {
                                    $component->state(array_map('trim', explode(',', config('dev.allowed_playlist_domains'))));
                                }
                            })
                            ->dehydrated(fn () => empty(config('dev.allowed_playlist_domains'))),
                    ]),
                Section::make(__('Xtream API Panel Settings'))
                    ->schema([
                        Grid::make()
                            ->columnSpanFull()
                            ->columns(2)
                            ->schema([
                                Toggle::make('app_output_enabled')
                                    ->label(__('Enhanced output enabled'))
                                    ->helperText(__('When enabled, the application will output additional Xtream API fields for the M3U TV app.'))
                                    ->hintAction(
                                        Action::make('tv_app_settings')
                                            ->label(__('TV App settings'))
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url(ManageTvAppSettings::getUrl())
                                    )
                                    ->columnSpanFull()
                                    ->default(true),
                                TextInput::make('xtream_api_details.http_port')
                                    ->label(__('HTTP Port'))
                                    ->numeric()
                                    ->placeholder(fn () => config('app.port', '80'))
                                    ->helperText(__('Returned as "server_info.http_port" in "player_api.php" responses. Leave empty to use APP_PORT (default).')),
                                TextInput::make('xtream_api_details.https_port')
                                    ->label(__('HTTPS Port'))
                                    ->numeric()
                                    ->placeholder(__('443'))
                                    ->helperText(__('Returned as "server_info.https_port" in "player_api.php" responses. Leave empty to use 443 (default).')),
                                Textarea::make('xtream_api_message')
                                    ->label(__('Xtream API panel message'))
                                    ->helperText(__('Returned as "user_info.message" in "player_api.php" responses.'))
                                    ->rows(3)
                                    ->columnSpanFull()
                                    ->default(''),
                            ]),
                    ]),
            ]);
    }
}
