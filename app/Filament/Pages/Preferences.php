<?php

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Support\Enums\Width;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Exception;
use ReflectionClass;
use ReflectionProperty;
use App\Rules\CheckIfUrlOrLocalPath;
use App\Settings\GeneralSettings;
use App\Services\FfmpegCodecService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class Preferences extends SettingsPage
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $settings = GeneralSettings::class;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    public function form(Schema $schema): Schema
    {
        $ffmpegPath = config('proxy.ffmpeg_path');
        return $schema
            ->components([
                Tabs::make()
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Appearance')
                            ->schema([
                                Section::make('Layout options')
                                    ->schema([
                                        Select::make('navigation_position')
                                            ->label('Navigation position')
                                            ->helperText('Choose the position of primary navigation')
                                            ->options([
                                                'left' => 'Left',
                                                'top' => 'Top',
                                            ]),
                                        Toggle::make('show_breadcrumbs')
                                            ->label('Show breadcrumbs')
                                            ->helperText('Show breadcrumbs under the page titles'),
                                        Select::make('content_width')
                                            ->label('Max width of the page content')
                                            ->options([
                                                Width::ScreenMedium->value => 'Medium',
                                                Width::ScreenLarge->value => 'Large',
                                                Width::ScreenExtraLarge->value => 'XL',
                                                Width::ScreenTwoExtraLarge->value => '2XL',
                                                Width::Full->value => 'Full',
                                            ]),
                                    ]),
                            ]),
                        Tab::make('Proxy')
                            ->schema([
                                Section::make('Internal Proxy')
                                    ->description('FFmpeg proxy settings')
                                    ->columnSpan('full')
                                    ->columns(3)
                                    ->collapsible()
                                    ->collapsed(false)
                                    ->schema([
                                        Grid::make()
                                            ->columnSpanFull()
                                            ->columns(2)
                                            ->schema([
                                                Toggle::make('ffmpeg_debug')
                                                    ->label('Debug')
                                                    ->columnSpan(1)
                                                    ->helperText('When enabled FFmpeg will output verbose logging to the log file (/var/www/logs/ffmpeg-YYYY-MM-DD.log). When disabled, FFmpeg will only log errors.'),
                                                Toggle::make('force_video_player_proxy')
                                                    ->label('Force Video Player Proxy')
                                                    ->columnSpan(1)
                                                    ->helperText('When enabled, the in-app video player will always use the proxy. This can be useful to bypass mixed content issues when using HTTPS. When disabled, the video player will respect the playlist proxy settings.'),
                                            ]),
                                        Grid::make()
                                            ->columnSpanFull()
                                            ->columns(2)
                                            ->schema([
                                                Select::make('ffmpeg_path')
                                                    ->label('FFmpeg')
                                                    ->columnSpan(1)
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
                                                Select::make('ffprobe_path')
                                                    ->label('FFprobe')
                                                    ->columnSpan(1)
                                                    ->helperText('Which ffprobe variant would you like to use.')
                                                    ->options([
                                                        'jellyfin-ffprobe' => 'jellyfin-ffprobe (default)',
                                                        'ffprobe' => 'ffprobe',
                                                    ])
                                                    ->searchable()
                                                    // Assuming similar logic for ffprobe path being set by env var
                                                    ->suffixIcon(fn() => !empty(config('proxy.ffprobe_path')) ? 'heroicon-m-lock-closed' : null)
                                                    ->disabled(fn() => !empty(config('proxy.ffprobe_path')))
                                                    ->hint(fn() => !empty(config('proxy.ffprobe_path')) ? 'Already set by environment variable!' : null)
                                                    ->dehydrated(fn() => empty(config('proxy.ffprobe_path')))
                                                    ->placeholder(fn() => empty(config('proxy.ffprobe_path')) ? 'jellyfin-ffprobe' : config('proxy.ffprobe_path')),
                                            ]),
                                        TextInput::make('ffmpeg_max_tries')
                                            ->label('Max tries')
                                            ->columnSpan(1)
                                            ->required()
                                            ->type('number')
                                            ->default(3)
                                            ->minValue(0)
                                            ->helperText('If the FFmpeg process crashes or fails for any reason, how many times should it try to reconnect before aborting?'),
                                        TextInput::make('ffmpeg_ffprobe_timeout')
                                            ->label('FFprobe Timeout (seconds)')
                                            ->columnSpan(1)
                                            ->type('number')
                                            ->minValue(1)
                                            ->default(5)
                                            ->helperText('Timeout for ffprobe pre-check in seconds. Default: 5.'),
                                        TextInput::make('ffmpeg_user_agent')
                                            ->label('User agent')
                                            ->required()
                                            ->columnSpan(1)
                                            ->default('VLC/3.0.21 LibVLC/3.0.21')
                                            ->placeholder('VLC/3.0.21 LibVLC/3.0.21')
                                            ->helperText('Fallback user agent (defaults to the streams Playlist user agent, when set).'),
                                        TextInput::make('ffmpeg_hls_time')
                                            ->label('HLS Time (seconds)')
                                            ->columnSpan(1)
                                            ->type('number')
                                            ->minValue(1)
                                            ->default(4)
                                            ->helperText('Target HLS segment duration in seconds. Default: 4.'),
                                        TextInput::make('hls_playlist_max_attempts')
                                            ->label('HLS Playlist Max Wait Attempts')
                                            ->columnSpan(1)
                                            ->type('number')
                                            ->minValue(1)
                                            ->default(10)
                                            ->helperText('Max attempts to wait for HLS playlist. Default: 10.'),
                                        TextInput::make('hls_playlist_sleep_seconds')
                                            ->label('HLS Playlist Wait Sleep (seconds)')
                                            ->columnSpan(1)
                                            ->type('number')
                                            ->step('0.1')
                                            ->minValue(0.1)
                                            ->default(1.0)
                                            ->helperText('Seconds to sleep between playlist checks. Default: 1.0s.'),

                                    ]),
                                Section::make('Advanced FFmpeg Settings')
                                    ->description('These settings allow you to customize the FFmpeg transcoding process. Use with caution, as incorrect settings can lead to poor performance or compatibility issues.')
                                    ->collapsible()
                                    ->collapsed(true)
                                    ->schema([
                                        Select::make('hardware_acceleration_method')
                                            ->label('Hardware Acceleration')
                                            ->options([
                                                'none' => 'None',
                                                'qsv' => 'Intel QSV',
                                                'vaapi' => 'VA-API',
                                            ])
                                            ->live()
                                            ->columnSpanFull()
                                            ->helperText('Choose the hardware acceleration method for FFmpeg.')
                                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                                                $currentVideoCodec = $get('ffmpeg_codec_video');

                                                if ($state === 'none') {
                                                    // If hardware acceleration is set to 'none',
                                                    // check if the current video codec is a hardware-specific one.
                                                    if ($currentVideoCodec && FfmpegCodecService::isHardwareVideoCodec($currentVideoCodec)) {
                                                        // If it is, reset the video codec to 'Copy Original' (empty string).
                                                        $set('ffmpeg_codec_video', '');
                                                    }
                                                } else {
                                                    // If a specific hardware acceleration is chosen (qsv, vaapi),
                                                    // or if the state is somehow null/unexpected (though 'none' covers empty state).
                                                    if ($currentVideoCodec) { // Only proceed if a codec is actually set
                                                        $newValidCodecs = FfmpegCodecService::getVideoCodecs($state);
                                                        if (!array_key_exists($currentVideoCodec, $newValidCodecs)) {
                                                            // If the current codec is not valid for the new hardware acceleration method,
                                                            // reset it to 'Copy Original'.
                                                            $set('ffmpeg_codec_video', '');
                                                        }
                                                    }
                                                }
                                            })
                                            ->suffixAction(
                                                Action::make('about_hardware_acceleration')
                                                    ->icon('heroicon-m-information-circle')
                                                    ->modalContent(view('modals.hardware-accel-info'))
                                                    ->modalHeading('About Hardware Acceleration')
                                                    ->modalWidth('xl')
                                                    ->modalSubmitAction(false)
                                                    ->modalCancelAction(fn($action) => $action->label('Close'))
                                            ),

                                        TextInput::make('ffmpeg_vaapi_device')
                                            ->label('VA-API Device Path')
                                            ->columnSpan('full')
                                            ->default('/dev/dri/renderD128')
                                            ->placeholder('/dev/dri/renderD128')
                                            ->helperText('e.g., /dev/dri/renderD128 or /dev/dri/card0')
                                            ->visible(fn(Get $get) => $get('hardware_acceleration_method') === 'vaapi'),
                                        TextInput::make('ffmpeg_vaapi_video_filter')
                                            ->label('VA-API Video Filter')
                                            ->columnSpan('full')
                                            ->default('scale_vaapi=format=nv12')
                                            ->placeholder('scale_vaapi=format=nv12')
                                            ->helperText("e.g., scale_vaapi=w=1280:h=720:format=nv12. Applied using -vf. Ensure 'format=' is usually nv12 or vaapi.")
                                            ->visible(fn(Get $get) => $get('hardware_acceleration_method') === 'vaapi'),

                                        TextInput::make('ffmpeg_qsv_device')
                                            ->label('QSV Device Path')
                                            ->columnSpan('full')
                                            ->placeholder('/dev/dri/renderD128')
                                            ->helperText('e.g., /dev/dri/renderD128. This is passed to init_hw_device.')
                                            ->visible(fn(Get $get) => $get('hardware_acceleration_method') === 'qsv'),
                                        TextInput::make('ffmpeg_qsv_video_filter')
                                            ->label('QSV Video Filter (Optional)')
                                            ->columnSpan('full')
                                            ->placeholder('vpp_qsv=w=1280:h=720:format=nv12')
                                            ->helperText('e.g., vpp_qsv=w=1280:h=720:format=nv12 for scaling. Applied using -vf.')
                                            ->visible(fn(Get $get) => $get('hardware_acceleration_method') === 'qsv'),
                                        Textarea::make('ffmpeg_qsv_encoder_options')
                                            ->label('QSV Encoder Options (Optional)')
                                            ->columnSpan('full')
                                            ->placeholder('e.g., -profile:v high -g 90 -look_ahead 1')
                                            ->helperText('Additional options for the h264_qsv (or hevc_qsv) encoder.')
                                            ->rows(3)
                                            ->visible(fn(Get $get) => $get('hardware_acceleration_method') === 'qsv'),
                                        Textarea::make('ffmpeg_qsv_additional_args')
                                            ->label('Additional QSV Arguments (Optional)')
                                            ->columnSpan('full')
                                            ->placeholder('e.g., -low_power 1 for some QSV encoders')
                                            ->helperText('Advanced: Additional FFmpeg arguments specific to your QSV setup. Use with caution.')
                                            ->rows(3)
                                            ->visible(fn(Get $get) => $get('hardware_acceleration_method') === 'qsv'),

                                        $this->makeCodecSelect('video', 'ffmpeg_codec_video', $schema),
                                        $this->makeCodecSelect('audio', 'ffmpeg_codec_audio', $schema),
                                        $this->makeCodecSelect('subtitle', 'ffmpeg_codec_subtitles', $schema),

                                        Textarea::make('ffmpeg_custom_command_template')
                                            ->label('Custom FFmpeg Command Template')
                                            ->columnSpanFull()
                                            ->nullable()
                                            ->placeholder('e.g., {FFMPEG_PATH} -hwaccel vaapi -hwaccel_device /dev/dri/renderD128 -i {INPUT_URL} -vf "scale_vaapi=format=nv12" {OUTPUT_OPTIONS}')
                                            ->rows(5)
                                            ->helperText('Define a full FFmpeg command template. Use placeholders like {FFMPEG_PATH}, {INPUT_URL}, {OUTPUT_OPTIONS}, {USER_AGENT}, {REFERER}, {HWACCEL_INIT_ARGS}, {HWACCEL_ARGS}, {VIDEO_FILTER_ARGS}, {AUDIO_CODEC_ARGS}, {VIDEO_CODEC_ARGS}, {SUBTITLE_CODEC_ARGS}. If this field is filled, it will override most other FFmpeg settings. Leave empty to use the application-generated command. Use with caution: an improperly configured custom command can expose security vulnerabilities or cause instability.'),
                                    ])->columns(3),
                                Section::make('MediaFlow Proxy')
                                    ->description('If you have MediaFlow Proxy installed, you can use it to proxy your m3u editor playlist streams. When enabled, the app will auto-generate URLs for you to use via MediaFlow Proxy.')
                                    ->columnSpan('full')
                                    ->columns(3)
                                    ->collapsible()
                                    ->collapsed(true)
                                    ->headerActions([
                                        Action::make('mfproxy_git')
                                            ->label('GitHub')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->url('https://github.com/mhdzumair/mediaflow-proxy')
                                            ->openUrlInNewTab(true)
                                    ])
                                    ->schema([
                                        TextInput::make('mediaflow_proxy_url')
                                            ->label('Proxy URL')
                                            ->columnSpan(1)
                                            ->placeholder('socks5://user:pass@host:port or http://user:pass@host:port'),
                                        TextInput::make('mediaflow_proxy_port')
                                            ->label('Proxy Port (Alternative)')
                                            ->numeric()
                                            ->columnSpan(1)
                                            ->helperText('Alternative port if not specified in URL. Not commonly used.'),

                                        TextInput::make('mediaflow_proxy_password')
                                            ->label('Proxy Password (Alternative)')
                                            ->columnSpan(1)
                                            ->password()
                                            ->revealable()
                                            ->helperText('Alternative password if not specified in URL. Not commonly used.'),
                                        Toggle::make('mediaflow_proxy_playlist_user_agent')
                                            ->label('Use playlist user agent')
                                            ->inline(false)
                                            ->live()
                                            ->label('Use Proxy User Agent for Playlists (M3U8/MPD)')
                                            ->helperText('If enabled, the User Agent will also be used for fetching playlist files. Otherwise, the default FFmpeg User Agent is used for playlists.'),
                                        TextInput::make('mediaflow_proxy_user_agent')
                                            ->label('Proxy User Agent for Media Streams')
                                            ->placeholder('VLC/3.0.21 LibVLC/3.0.21')
                                            ->columnSpan(2),
                                    ])
                            ]),

                        Tab::make('Sync Options')
                            ->schema([
                                Section::make('Sync Invalidation')
                                    ->description('Prevent sync from proceeding if conditions are met.')
                                    ->columnSpan('full')
                                    ->columns(1)
                                    ->collapsible(false)
                                    ->schema([
                                        Toggle::make('invalidate_import')
                                            ->label('Enable import invalidation')
                                            ->disabled(fn() => !empty(config('dev.invalidate_import')))
                                            ->hint(fn() => !empty(config('dev.invalidate_import')) ? 'Already set by environment variable!' : null)
                                            ->default(function () {
                                                return !empty(config('dev.invalidate_import')) ? (bool) config('dev.invalidate_import') : false;
                                            })
                                            ->afterStateHydrated(function (Toggle $component, $state) {
                                                if (!empty(config('dev.invalidate_import'))) {
                                                    $component->state((bool) config('dev.invalidate_import'));
                                                }
                                            })
                                            ->dehydrated(fn() => empty(config('dev.invalidate_import')))
                                            ->helperText('Invalidate Playlist sync if conditon met.'),
                                        TextInput::make('invalidate_import_threshold')
                                            ->label('Import invalidation threshold')
                                            ->suffixIcon(fn() => !empty(config('dev.invalidate_import_threshold')) ? 'heroicon-m-lock-closed' : null)
                                            ->disabled(fn() => !empty(config('dev.invalidate_import_threshold')))
                                            ->hint(fn() => !empty(config('dev.invalidate_import_threshold')) ? 'Already set by environment variable!' : null)
                                            ->dehydrated(fn() => empty(config('dev.invalidate_import_threshold')))
                                            ->placeholder(fn() => empty(config('dev.invalidate_import_threshold')) ? 100 : config('dev.invalidate_import_threshold'))
                                            ->numeric()
                                            ->helperText('If the current sync will have less channels than the current channel count (less this value), the sync will be invalidated and canceled.'),
                                    ]),
                                Section::make('Stream location file settings')
                                    ->description('Generate .strm files and sync them to a local file path. Options can be overriden per Series on the Series edit page.')
                                    ->columnSpan('full')
                                    ->columns(1)
                                    ->collapsible(false)
                                    ->schema([
                                        Toggle::make('stream_file_sync_enabled')
                                            ->live()
                                            ->label('Enable .strm file generation'),
                                        Toggle::make('stream_file_sync_include_series')
                                            ->label('Create series folder')
                                            ->live()
                                            ->default(true)
                                            ->hidden(fn($get) => !$get('stream_file_sync_enabled')),
                                        Toggle::make('stream_file_sync_include_season')
                                            ->label('Create season folders')
                                            ->live()
                                            ->default(true)
                                            ->hidden(fn($get) => !$get('stream_file_sync_enabled')),
                                        TextInput::make('stream_file_sync_location')
                                            ->label('Series Sync Location')
                                            ->live()
                                            ->rules([new CheckIfUrlOrLocalPath(localOnly: true, isDirectory: true)])
                                            ->helperText(
                                                fn($get) => !$get('stream_file_sync_include_series')
                                                    ? 'File location: ' . $get('stream_file_sync_location') . ($get('stream_file_sync_include_season') ?? false ? '/Season 01' : '') . '/S01E01 - Episode Title.strm'
                                                    : 'File location: ' . $get('stream_file_sync_location') . '/Series Name' . ($get('stream_file_sync_include_season') ?? false ? '/Season 01' : '') . '/S01E01 - Episode Title.strm'
                                            )
                                            ->maxLength(255)
                                            ->required()
                                            ->hidden(fn($get) => !$get('stream_file_sync_enabled'))
                                            ->placeholder('/usr/local/bin/streamlink'),
                                    ])
                            ]),

                        Tab::make('API')
                            ->schema([
                                Section::make('API Settings')
                                    ->headerActions([
                                        Action::make('manage_api_keys')
                                            ->label('Manage API Tokens')
                                            ->color('gray')
                                            ->icon('heroicon-s-key')
                                            ->iconPosition('before')
                                            ->size('sm')
                                            ->url('/personal-access-tokens'),
                                        Action::make('view_api_docs')
                                            ->label('API Docs')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/docs/api')
                                            ->openUrlInNewTab(true),
                                    ])->schema([
                                        Toggle::make('show_api_docs')
                                            ->label('Allow access to API docs')
                                            ->helperText('When enabled you can access the API documentation using the "API Docs" button. When disabled, the docs endpoint will return a 403 (Unauthorized). NOTE: The API will respond regardless of this setting. You do not need to enable it to use the API.'),
                                    ])
                            ]),
                        Tab::make('SMTP')
                            ->columns(2)
                            ->schema([
                                Section::make('SMTP Settings')
                                    ->description('Configure SMTP settings to send emails from the application.')
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->headerActions([
                                        Action::make('send_test_email')
                                            ->label('Send Test Email')
                                            ->icon('heroicon-o-envelope')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->modalWidth('md')
                                            ->schema([
                                                TextInput::make('to_email')
                                                    ->label('To Email Address')
                                                    ->email()
                                                    ->required()
                                                    ->placeholder('Enter To Email Address')
                                                    ->helperText('A test email will be sent to this address using the entered SMTP settings.'),
                                            ])
                                            ->action(function (array $data, $get): void {
                                                try {
                                                    // Get SMTP settings from the form state
                                                    $formState = $this->form->getState();

                                                    // Make sure all required fields are present
                                                    if (empty($formState['smtp_host']) || empty($formState['smtp_port']) || empty($formState['smtp_username']) || empty($formState['smtp_password'])) {
                                                        Notification::make()
                                                            ->danger()
                                                            ->title('Missing SMTP Fields')
                                                            ->body('Please fill in all required SMTP fields before sending a test email.')
                                                            ->send();
                                                        return;
                                                    }

                                                    // Configure mail settings temporarily
                                                    Config::set('mail.default', 'smtp');
                                                    Config::set('mail.from.address', $formState['smtp_from_address'] ?? 'no-reply@m3u-editor.dev');
                                                    Config::set('mail.from.name', 'm3u editor');
                                                    Config::set('mail.mailers.smtp.host', $formState['smtp_host']);
                                                    Config::set('mail.mailers.smtp.username', $formState['smtp_username']);
                                                    Config::set('mail.mailers.smtp.password', $formState['smtp_password']);
                                                    Config::set('mail.mailers.smtp.port', $formState['smtp_port']);
                                                    Config::set('mail.mailers.smtp.encryption', $formState['smtp_encryption']);

                                                    Mail::raw('This is a test email to verify your SMTP settings.', function ($message) use ($data) {
                                                        $message->to($data['to_email'])
                                                            ->subject('Test Email from m3u editor');
                                                    });

                                                    Notification::make()
                                                        ->success()
                                                        ->title('Test Email Sent')
                                                        ->body('Test email sent successfully to ' . $data['to_email'])
                                                        ->send();
                                                } catch (Exception $e) {
                                                    Notification::make()
                                                        ->danger()
                                                        ->title('Error Sending Test Email')
                                                        ->body($e->getMessage())
                                                        ->send();
                                                }
                                            }),
                                    ])
                                    ->schema([
                                        TextInput::make('smtp_host')
                                            ->label('SMTP Host')
                                            ->placeholder('Enter SMTP Host')
                                            ->requiredWith('smtp_port')
                                            ->helperText('Required to send emails.'),
                                        TextInput::make('smtp_port')
                                            ->label('SMTP Port')
                                            ->placeholder('Enter SMTP Port')
                                            ->requiredWith('smtp_host')
                                            ->numeric()
                                            ->helperText('Required to send emails.'),
                                        TextInput::make('smtp_username')
                                            ->label('SMTP Username')
                                            ->placeholder('Enter SMTP Username')
                                            ->requiredWith('smtp_password')
                                            ->helperText('Required to send emails, if your provider requires authentication.'),
                                        TextInput::make('smtp_password')
                                            ->label('SMTP Password')
                                            ->revealable()
                                            ->placeholder('Enter SMTP Password')
                                            ->requiredWith('smtp_username')
                                            ->password()
                                            ->helperText('Required to send emails, if your provider requires authentication.'),
                                        Select::make('smtp_encryption')
                                            ->label('SMTP Encryption')
                                            ->options([
                                                'tls' => 'TLS',
                                                'ssl' => 'SSL',
                                                null => 'None',
                                            ])
                                            ->placeholder('Select encryption type (optional)'),
                                        TextInput::make('smtp_from_address')
                                            ->label('SMTP From Address')
                                            ->placeholder('Enter SMTP From Address')
                                            ->email()
                                            ->helperText('The "From" email address for outgoing emails. Defaults to no-reply@m3u-editor.dev.'),
                                    ]),
                            ]),
                        Tab::make('Debugging')
                            ->schema([
                                Section::make('Debugging')
                                    ->headerActions([
                                        Action::make('test_websocket')
                                            ->label('Test WebSocket')
                                            ->icon('heroicon-o-signal')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->modalWidth('md')
                                            ->schema([
                                                TextInput::make('message')
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
                                                    ->broadcast(Auth::user());
                                            }),
                                        Action::make('view_logs')
                                            ->label('View Logs')
                                            ->color('gray')
                                            ->icon('heroicon-o-document-text')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/logs'),
                                        Action::make('view_queue_manager')
                                            ->label('Queue Manager')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/horizon')
                                            ->openUrlInNewTab(true),
                                    ])->schema([
                                        Toggle::make('show_logs')
                                            ->label('Make log files viewable')
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: 'You may need to refresh the page after applying this setting to view the logs. When disabled you will get a 404.'
                                            )
                                            ->helperText('When enabled, there will be an additional navigation item (Logs) to view the log file content.'),
                                        Toggle::make('show_queue_manager')
                                            ->label('Allow queue manager access')
                                            ->helperText('When enabled you can access the queue manager using the "Queue Manager" button. When disabled, the queue manager endpoint will return a 403 (Unauthorized).'),
                                    ]),
                            ]),
                    ])->contained(false)
            ]);
    }

    /**
     * Create a Select component for codec selection with dynamic options based on hardware acceleration method.
     *
     * @param string $label The label for the codec type (e.g., 'video', 'audio', 'subtitle').
     * @param string $field The field name for the codec in the settings.
     * @param \Filament\Schemas\Schema $schema The form instance to which this component belongs.
     * @return Select
     */
    private function makeCodecSelect(
        string $label,
        string $field,
        Schema $schema
    ): Select {
        $configKey = "proxy.{$field}";
        $configValue = config($configKey);

        return Select::make($field)
            ->label(ucwords($label) . ' codec')
            ->helperText("Transcode {$label} streams to this codec.\nLeave blank to copy the original.")
            ->allowHtml()
            ->searchable()
            ->live()
            ->noSearchResultsMessage('No codecs found.')
            ->options(function (Get $get) use ($label) {
                $accelerationMethod = $get('hardware_acceleration_method');
                switch ($label) {
                    case 'video':
                        return FfmpegCodecService::getVideoCodecs($accelerationMethod);
                    case 'audio':
                        return FfmpegCodecService::getAudioCodecs($accelerationMethod);
                    case 'subtitle':
                        return FfmpegCodecService::getSubtitleCodecs($accelerationMethod);
                    default:
                        return [];
                }
            })
            ->placeholder(fn() => empty($configValue) ? 'copy' : $configValue)
            ->suffixIcon(fn() => !empty($configValue) ? 'heroicon-m-lock-closed' : null)
            ->disabled(fn() => !empty($configValue))
            ->hint(fn() => !empty($configValue) ? 'Already set by environment variable!' : null)
            ->dehydrated(fn() => empty($configValue));
    }

    /**
     * Mutate the submitted form data before saving it to the settings.
     *
     * This method ensures that only valid settings properties are updated,
     * handles conditional nullification of QSV and VAAPI fields based on the
     * selected hardware acceleration method, and converts empty strings to null
     * for specific nullable text fields.
     *
     * @param array $submittedFormData The data submitted from the form.
     * @return array The final settings data to be saved.
     */
    protected function mutateFormDataBeforeSave(array $submittedFormData): array
    {
        $settingsClass = static::getSettings(); // Or static::$settings
        $loadedSettings = app($settingsClass); // Instance of GeneralSettings with current values

        // Start with all existing settings properties and their current values.
        $finalData = $loadedSettings->toArray();

        // Update with submitted data for fields that were part of the form
        // AND are actual public properties of the settings class.
        // This loop ensures we only consider keys that are valid settings properties.
        $reflectionClass = new ReflectionClass($settingsClass);
        $publicProperties = [];
        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $publicProperties[$property->getName()] = true;
        }

        foreach ($submittedFormData as $key => $value) {
            if (isset($publicProperties[$key])) { // Check if submitted key is a defined public property
                $finalData[$key] = $value;
            }
        }

        // Nullify QSV fields if QSV is not the selected hardware acceleration method.
        // Use $finalData for checking 'hardware_acceleration_method' as it's now the complete picture.
        if (isset($finalData['hardware_acceleration_method']) && $finalData['hardware_acceleration_method'] !== 'qsv') {
            $finalData['ffmpeg_qsv_device'] = null;
            $finalData['ffmpeg_qsv_video_filter'] = null;
            $finalData['ffmpeg_qsv_encoder_options'] = null;
            $finalData['ffmpeg_qsv_additional_args'] = null; // Ensure this is included
        }

        // Nullify VAAPI fields if VA-API is not the selected method.
        if (isset($finalData['hardware_acceleration_method']) && $finalData['hardware_acceleration_method'] !== 'vaapi') {
            $finalData['ffmpeg_vaapi_device'] = null;
            $finalData['ffmpeg_vaapi_video_filter'] = null;
        }

        // Convert empty strings from text inputs to null for nullable fields.
        // This should run after ensuring all keys are present and conditional nulling is done.
        $nullableTextfields = [
            'ffmpeg_codec_video',
            'ffmpeg_codec_audio',
            'ffmpeg_codec_subtitles',
            'ffmpeg_vaapi_device',
            'ffmpeg_vaapi_video_filter',
            'ffmpeg_qsv_device',
            'ffmpeg_qsv_video_filter',
            'ffmpeg_qsv_encoder_options',
            'ffmpeg_qsv_additional_args',
            'ffmpeg_custom_command_template',
            // mediaflow fields were removed, but if others exist that are text & nullable, add here
            // 'mediaflow_proxy_url', 'mediaflow_proxy_port', 'mediaflow_proxy_password',
            // 'mediaflow_proxy_user_agent', 
            'ffmpeg_path',
            'ffprobe_path'
        ];

        foreach ($nullableTextfields as $field) {
            // Ensure the field exists in finalData before checking if it's an empty string.
            // This is important because some fields (like QSV/VAAPI ones) might already be null.
            if (array_key_exists($field, $finalData) && $finalData[$field] === '') {
                $finalData[$field] = null;
            }
        }

        // Ensure 'hardware_acceleration_method' itself has a default if it was somehow missing
        // from both loaded settings and submitted form data (highly unlikely for a form field).
        // The initial $finalData = $loadedSettings->toArray() should cover this.
        // If $loadedSettings didn't have it (e.g. fresh install, no defaults run), 
        // it might still be an issue for Spatie settings if it's not nullable and has no default in the class.
        // However, 'hardware_acceleration_method' has a default in GeneralSettings.php.

        return $finalData;
    }
}
