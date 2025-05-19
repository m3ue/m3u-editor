<?php

namespace App\Filament\Pages;

use App\Models\CustomPlaylist;
use App\Settings\GeneralSettings;
use App\Services\FfmpegCodecService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Support\Enums\MaxWidth;
use Forms\Components;

class Preferences extends SettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $settings = GeneralSettings::class;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    public array $videoCodecs = [];
    public array $audioCodecs = [];
    public array $subtitleCodecs = [];

    // Loads codec lists on mount and caches the values
    public function mount(): void
    {
        parent::mount();

        $codecs = app(FfmpegCodecService::class)->getEncoders();

        $this->videoCodecs = $codecs['video'];
        $this->audioCodecs = $codecs['audio'];
        $this->subtitleCodecs = $codecs['subtitle'];
    }

    public function form(Form $form): Form
    {
        $ffmpegPath = config('proxy.ffmpeg_path');
        return $form
            ->schema([
                Forms\Components\Tabs::make()
                    ->columnSpanFull()
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Appearance')
                            ->schema([
                                Forms\Components\Select::make('navigation_position')
                                    ->label('Navigation position')
                                    ->helperText('Choose the position of primary navigation')
                                    ->options([
                                        'left' => 'Left',
                                        'top' => 'Top',
                                    ]),
                                Forms\Components\Toggle::make('show_breadcrumbs')
                                    ->label('Show breadcrumbs')
                                    ->helperText('Show breadcrumbs under the page titles'),
                                Forms\Components\Select::make('content_width')
                                    ->label('Max width of the page content')
                                    ->options([
                                        MaxWidth::ScreenMedium->value => 'Medium',
                                        MaxWidth::ScreenLarge->value => 'Large',
                                        MaxWidth::ScreenExtraLarge->value => 'XL',
                                        MaxWidth::ScreenTwoExtraLarge->value => '2XL',
                                        MaxWidth::Full->value => 'Full',
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Proxy')
                            ->schema([
                                Forms\Components\Section::make('Internal Proxy')
                                    ->description('FFmpeg proxy settings')
                                    ->columnSpan('full')
                                    ->columns(3)
                                    ->schema([
                                        Forms\Components\Toggle::make('ffmpeg_debug')
                                            ->label('Debug')
                                            ->columnSpan(1)
                                            ->helperText('When enabled FFmpeg will output verbose logging to the log file (/var/www/logs/ffmpeg-YYYY-MM-DD.log). When disabled, FFmpeg will only log errors.'),
                                        Forms\Components\Select::make('ffmpeg_path')
                                            ->label('FFmpeg')
                                            ->columnSpan(2)
                                            ->helperText('Which ffmpeg variant would you like to use.')
                                            ->options([
                                                'jellyfin-ffmpeg' => 'jellyfin-ffmpeg (default)',
                                                'ffmpeg' => 'ffmpeg (v6)',
                                            ])
                                            ->searchable()
                                            ->suffixIcon(fn() => !empty($ffmpegPath) ? 'heroicon-m-lock-closed' : null)
                                            ->disabled(fn() => !empty($ffmpegPath))
                                            ->hint(fn() => !empty($ffmpegPath) ? 'Already set by environment variable!' : null)
                                            ->dehydrated(fn() => empty($ffmpegPath))
                                            ->placeholder(fn() => empty($ffmpegPath) ? 'jellyfin-ffmpeg' : $ffmpegPath),
                                        Forms\Components\TextInput::make('ffmpeg_max_tries')
                                            ->label('Max tries')
                                            ->columnSpan(1)
                                            ->required()
                                            ->type('number')
                                            ->default(3)
                                            ->minValue(0)
                                            ->helperText('If the FFMpeg process crashes or fails for any reason, how many times should it try to reconnect before aborting?'),
                                        Forms\Components\TextInput::make('ffmpeg_user_agent')
                                            ->label('User agent')
                                            ->required()
                                            ->columnSpan(1)
                                            ->default('VLC/3.0.21 LibVLC/3.0.21')
                                            ->placeholder('VLC/3.0.21 LibVLC/3.0.21')
                                            ->helperText(''),
                                        $this->makeCodecSelect('video', 'ffmpeg_codec_video', 'videoCodecs'),
                                        $this->makeCodecSelect('audio', 'ffmpeg_codec_audio', 'audioCodecs'),
                                        $this->makeCodecSelect('subtitle', 'ffmpeg_codec_subtitles', 'subtitleCodecs'),
                                    ]),
                                Forms\Components\Section::make('MediaFlow Proxy')
                                    ->description('If you have MediaFlow Proxy installed, you can use it to proxy your m3u editor playlist streams. When enabled, the app will auto-generate URLs for you to use via MediaFlow Proxy.')
                                    ->columnSpan('full')
                                    ->columns(3)
                                    ->headerActions([
                                        Forms\Components\Actions\Action::make('mfproxy_git')
                                            ->label('GitHub')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->url('https://github.com/mhdzumair/mediaflow-proxy')
                                            ->openUrlInNewTab(true)
                                    ])
                                    ->schema([
                                        Forms\Components\TextInput::make('mediaflow_proxy_url')
                                            ->label('URL')
                                            ->columnSpan(1)
                                            ->placeholder('http://localhost'),
                                        Forms\Components\TextInput::make('mediaflow_proxy_port')
                                            ->label('Port')
                                            ->type('number')
                                            ->columnSpan(1)
                                            ->placeholder(8888),
                                        Forms\Components\TextInput::make('mediaflow_proxy_password')
                                            ->label('API Password')
                                            ->columnSpan(1)
                                            ->password()
                                            ->revealable(),
                                        Forms\Components\Toggle::make('mediaflow_proxy_playlist_user_agent')
                                            ->label('Use playlist user agent')
                                            ->inline(false)
                                            ->live()
                                            ->helperText('Appends the Playlist user agent. Disable to use a custom user agent for all requests.'),
                                        Forms\Components\TextInput::make('mediaflow_proxy_user_agent')
                                            ->label('User agent')
                                            ->placeholder('VLC/3.0.21 LibVLC/3.0.21')
                                            ->columnSpan(2)
                                            ->hidden(fn(Get $get): bool => !!$get('mediaflow_proxy_playlist_user_agent')),
                                    ])
                            ]),
                        Forms\Components\Tabs\Tab::make('API')
                            ->schema([
                                Forms\Components\Section::make('API Settings')
                                    ->headerActions([
                                        Forms\Components\Actions\Action::make('manage_api_keys')
                                            ->label('Manage API Tokens')
                                            ->color('gray')
                                            ->icon('heroicon-s-key')
                                            ->iconPosition('before')
                                            ->size('sm')
                                            ->url('/profile'),
                                        Forms\Components\Actions\Action::make('view_api_docs')
                                            ->label('API Docs')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/docs/api')
                                            ->openUrlInNewTab(true),
                                    ])->schema([
                                        Forms\Components\Toggle::make('show_api_docs')
                                            ->label('Allow access to API docs')
                                            ->helperText('When enabled you can access the API documentation using the "API Docs" button. When disabled, the docs endpoint will return a 403 (Unauthorized). NOTE: The API will respond regardless of this setting. You do not need to enable it to use the API.'),
                                    ])
                            ]),
                        Forms\Components\Tabs\Tab::make('Debugging')
                            ->schema([
                                Forms\Components\Section::make('Debugging')
                                    ->headerActions([
                                        Forms\Components\Actions\Action::make('test_websocket')
                                            ->label('Test WebSocket')
                                            ->icon('heroicon-o-signal')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->form([
                                                Forms\Components\TextInput::make('message')
                                                    ->label('Message')
                                                    ->required()
                                                    ->default('Testing WebSocket connection')
                                                    ->helperText('This message will be sent to the WebSocket server, and displayed as a pop-up notification. If you do not see a notification shortly after sending, there is likely an issue with your WebSocket configuration.'),
                                            ])
                                            ->action(function (array $data): void {
                                                Notification::make()
                                                    ->success()
                                                    ->title("WebSocket Connection Test")
                                                    ->body($data['message'])
                                                    ->persistent()
                                                    ->broadcast(auth()->user());
                                            }),
                                        Forms\Components\Actions\Action::make('view_logs')
                                            ->label('View Logs')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/logs')
                                            ->openUrlInNewTab(true),
                                        Forms\Components\Actions\Action::make('view_queue_manager')
                                            ->label('Queue Manager')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/horizon')
                                            ->openUrlInNewTab(true),
                                    ])->schema([
                                        Forms\Components\Toggle::make('show_logs')
                                            ->label('Make log files viewable')
                                            ->helperText('When enabled you can view the log files using the "View Logs" button. When disabled, the logs endpoint will return a 403 (Unauthorized).'),
                                        Forms\Components\Toggle::make('show_queue_manager')
                                            ->label('Allow queue manager access')
                                            ->helperText('When enabled you can access the queue manager using the "Queue Manager" button. When disabled, the queue manager endpoint will return a 403 (Unauthorized).'),
                                    ]),
                            ]),
                    ])
            ]);
    }

    // Handles generation of the codec select fields
    private function makeCodecSelect(string $label, string $field, string $property): Forms\Components\Select
    {
        $configKey = "proxy.{$field}";
        $configValue = config($configKey);

        return Forms\Components\Select::make($field)
            ->label(ucwords($label) . ' codec')
            ->helperText("Transcode {$label} streams to this codec.\nLeave blank to copy the original.")
            ->allowHtml()
            ->searchable()
            ->noSearchResultsMessage('No codecs found.')
            ->options(fn() => $this->{$property})
            ->getSearchResultsUsing(function (string $search) use ($property): array {
                return collect($this->{$property})
                    ->filter(fn($description, $codec) => str_contains(strtolower($codec), strtolower($search)))
                    ->all();
            })
            ->getOptionLabelUsing(fn($value): ?string => $value)
            ->placeholder(fn() => empty($configValue) ? 'copy' : $configValue)
            ->suffixIcon(fn() => !empty($configValue) ? 'heroicon-m-lock-closed' : null)
            ->disabled(fn() => !empty($configValue))
            ->hint(fn() => !empty($configValue) ? 'Already set by environment variable!' : null)
            ->dehydrated(fn() => empty($configValue));
    }
}
