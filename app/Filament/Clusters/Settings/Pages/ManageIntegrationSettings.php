<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Actions\RegexTesterAction;
use App\Filament\Clusters\Settings\Pages\Concerns\BaseSettingsPage;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Http;

class ManageIntegrationSettings extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-m-puzzle-piece';

    protected static ?string $slug = 'integrations';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('Integrations');
    }

    public function getTitle(): string
    {
        return __('Integrations');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->persistTabInQueryString()
                    ->contained(false)
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make(__('TMDB'))
                            ->id('tmdb')
                            ->icon('heroicon-m-film')
                            ->schema([
                                Section::make(__('TMDB Integration'))
                                    ->description(__('Configure The Movie Database (TMDB) integration to automatically lookup and populate metadata IDs (TMDB, TVDB, IMDB) for your VOD content and Series.'))
                                    ->columnSpanFull()
                                    ->icon('heroicon-m-film')
                                    ->collapsible()
                                    ->columns(2)
                                    ->headerActions([
                                        Action::make('test_tmdb_connection')
                                            ->label(__('Test Connection'))
                                            ->icon('heroicon-o-signal')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->action(function ($get): void {
                                                $apiKey = $get('tmdb_api_key');
                                                if (empty($apiKey)) {
                                                    Notification::make()
                                                        ->danger()
                                                        ->title(__('API Key Required'))
                                                        ->body(__('Please enter a TMDB API key to test the connection.'))
                                                        ->send();

                                                    return;
                                                }

                                                try {
                                                    $response = Http::timeout(10)->get('https://api.themoviedb.org/3/configuration', [
                                                        'api_key' => $apiKey,
                                                    ]);

                                                    if ($response->successful()) {
                                                        Notification::make()
                                                            ->success()
                                                            ->title(__('Connection Successful'))
                                                            ->body(__('Successfully connected to TMDB API!'))
                                                            ->send();
                                                    } else {
                                                        $error = $response->json('status_message', 'Unknown error');
                                                        Notification::make()
                                                            ->danger()
                                                            ->title(__('Connection Failed'))
                                                            ->body("TMDB API returned an error: {$error}")
                                                            ->send();
                                                    }
                                                } catch (Exception $e) {
                                                    Notification::make()
                                                        ->danger()
                                                        ->title(__('Connection Failed'))
                                                        ->body('Could not connect to TMDB API: '.$e->getMessage())
                                                        ->send();
                                                }
                                            }),
                                        Action::make('get_tmdb_api_key')
                                            ->label(__('Get API Key'))
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('https://www.themoviedb.org/settings/api')
                                            ->openUrlInNewTab(true),
                                    ])
                                    ->schema([
                                        TextInput::make('tmdb_api_key')
                                            ->label(__('TMDB API Key'))
                                            ->placeholder(__('Enter your TMDB API Key (v3 auth)'))
                                            ->password()
                                            ->revealable()
                                            ->helperText(__('Your TMDB API key (v3 auth). You can get one for free at themoviedb.org.')),
                                        Select::make('tmdb_language')
                                            ->label(__('Search Language'))
                                            ->searchable()
                                            ->options([
                                                'en-US' => 'English (US)',
                                                'en-GB' => 'English (UK)',
                                                'de-DE' => 'German',
                                                'fr-FR' => 'French',
                                                'es-ES' => 'Spanish (Spain)',
                                                'es-MX' => 'Spanish (Mexico)',
                                                'it-IT' => 'Italian',
                                                'pt-PT' => 'Portuguese',
                                                'pt-BR' => 'Portuguese (Brazil)',
                                                'nl-NL' => 'Dutch',
                                                'pl-PL' => 'Polish',
                                                'ru-RU' => 'Russian',
                                                'ja-JP' => 'Japanese',
                                                'ko-KR' => 'Korean',
                                                'zh-CN' => 'Chinese (Simplified)',
                                                'zh-TW' => 'Chinese (Traditional)',
                                                'ar-SA' => 'Arabic',
                                                'tr-TR' => 'Turkish',
                                                'sv-SE' => 'Swedish',
                                                'da-DK' => 'Danish',
                                                'fi-FI' => 'Finnish',
                                                'no-NO' => 'Norwegian',
                                            ])
                                            ->default('en-US')
                                            ->helperText(__('Preferred language for TMDB searches.')),
                                        Toggle::make('tmdb_auto_lookup_on_import')
                                            ->label(__('Auto-lookup on metadata fetch'))
                                            ->helperText(__('Automatically lookup TMDB IDs when fetching metadata for VOD and Series. This may slow down imports and metadata fetching for large playlists.'))
                                            ->live()
                                            ->default(false),
                                        Toggle::make('tmdb_auto_create_groups')
                                            ->label(__('Auto-create groups/categories from TMDB genres'))
                                            ->helperText(__('When enabled, TMDB metadata fetching will automatically create new groups (for VOD) and categories (for Series) based on TMDB genres. When disabled, only existing groups/categories will be used.'))
                                            ->default(false),
                                        Fieldset::make(__('TMDB Auto-lookup Settings'))
                                            ->columnSpanFull()
                                            ->schema([
                                                ToggleButtons::make('tmdb_auto_lookup_all_new')
                                                    ->options([
                                                        'enabled' => __('Only enabled'),
                                                        'new' => __('All new'),
                                                        'both' => __('Both'),
                                                    ])
                                                    ->icons([
                                                        'enabled' => 'heroicon-s-check',
                                                        'new' => 'heroicon-s-plus',
                                                        'both' => 'heroicon-s-squares-plus',
                                                    ])
                                                    ->colors([
                                                        'enabled' => 'success',
                                                        'new' => 'primary',
                                                        'both' => 'primary',
                                                    ])
                                                    ->columnSpanFull()
                                                    ->grouped()
                                                    ->label(__('Auto-lookup scope'))
                                                    ->helperText(__('Whether to automatically lookup TMDB IDs for all new VOD and Series, or only those that are enabled (default), or both.'))
                                                    ->default('enabled'),
                                            ])->hidden(fn (Get $get): bool => ! (bool) $get('tmdb_auto_lookup_on_import')),
                                        TextInput::make('tmdb_rate_limit')
                                            ->label(__('Rate Limit (requests/second)'))
                                            ->placeholder(__('40'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(50)
                                            ->default(40)
                                            ->helperText(__('Maximum TMDB API requests per second. TMDB allows ~40 req/s for free accounts.')),
                                        TextInput::make('tmdb_confidence_threshold')
                                            ->label(__('Match Confidence Threshold (%)'))
                                            ->placeholder(__('80'))
                                            ->numeric()
                                            ->minValue(50)
                                            ->maxValue(100)
                                            ->default(80)
                                            ->helperText(__('Minimum title similarity percentage (50-100) required to accept a match. Higher values = stricter matching.')),
                                        TextInput::make('tmdb_min_vote_count')
                                            ->label(__('Minimum Vote Count'))
                                            ->placeholder(__('25'))
                                            ->columnSpanFull()
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(10000)
                                            ->default(25)
                                            ->helperText(__('Minimum number of TMDB votes required to trust and display that rating. Ratings backed by fewer votes are hidden rather than shown as potentially misleading.')),
                                        Section::make(__('Title Cleaning for TMDB Lookup'))
                                            ->icon('heroicon-m-scissors')
                                            ->description(__('Strip provider prefixes from VOD and Series titles before matching with TMDB. This helps improve matching accuracy by removing common prefixes like "EN - ", "4K-EN - ", "NF - ", etc.'))
                                            ->compact()
                                            ->columnSpanFull()
                                            ->schema([
                                                Grid::make()
                                                    ->columnSpanFull()
                                                    ->columns(2)
                                                    ->schema([
                                                        Toggle::make('vod_stream_file_sync_name_filter_enabled')
                                                            ->label(__('Strip provider prefixes from VOD titles before matching'))
                                                            ->helperText(__('Remove prefix patterns from VOD titles before searching TMDB.'))
                                                            ->live()
                                                            ->inline(false)
                                                            ->default(false),
                                                        TagsInput::make('vod_stream_file_sync_name_filter_patterns')
                                                            ->label(__('VOD title prefix patterns'))
                                                            ->placeholder(__('EN - '))
                                                            ->helperText(__('Strings to strip from VOD titles before TMDB lookup. Press [tab] or [return] to add each pattern.'))
                                                            ->hintAction(
                                                                RegexTesterAction::make(name: 'test-vod-name-filter', flags: 'u', samplesContext: 'vod_channels')
                                                            )
                                                            ->hidden(fn (Get $get): bool => ! (bool) $get('vod_stream_file_sync_name_filter_enabled')),
                                                    ]),
                                                Grid::make()
                                                    ->columnSpanFull()
                                                    ->columns(2)
                                                    ->schema([
                                                        Toggle::make('stream_file_sync_name_filter_enabled')
                                                            ->label(__('Strip provider prefixes from Series titles before matching'))
                                                            ->helperText(__('Remove prefix patterns from Series titles before searching TMDB.'))
                                                            ->live()
                                                            ->inline(false)
                                                            ->default(false),
                                                        TagsInput::make('stream_file_sync_name_filter_patterns')
                                                            ->label(__('Series title prefix patterns'))
                                                            ->placeholder(__('EN - '))
                                                            ->helperText(__('Strings to strip from Series titles before TMDB lookup. Press [tab] or [return] to add each pattern.'))
                                                            ->hintAction(
                                                                RegexTesterAction::make(name: 'test-series-name-filter', flags: 'u', samplesContext: 'series')
                                                            )
                                                            ->hidden(fn (Get $get): bool => ! (bool) $get('stream_file_sync_name_filter_enabled')),
                                                    ]),
                                            ]),
                                    ]),
                            ]),
                        Tab::make(__('AIOStreams'))
                            ->id('aiostreams')
                            ->icon('heroicon-m-signal')
                            ->schema([
                                Section::make(__('AIOStreams Integration'))
                                    ->description(__('Tune how aggressively m3u-editor calls your AIOStreams addon instance(s) when browsing catalogs and resolving streams.'))
                                    ->columnSpanFull()
                                    ->icon('heroicon-m-signal')
                                    ->collapsible()
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('aiostreams_rate_limit')
                                            ->label(__('Rate Limit (requests/minute)'))
                                            ->placeholder(__('20'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(120)
                                            ->default(20)
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: __('Applies to every request m3u-editor makes to an AIOStreams instance (manifest, catalog, stream, and meta lookups combined), per integration. Keep this low if you use a shared/hosted AIOStreams instance you don\'t control the server-side rate limits for - AIOStreams\' own defaults for the addon endpoints it exposes range from ~40 to ~360 requests/minute depending on endpoint, so the default here is still well below the strictest of those.')
                                            )
                                            ->helperText(__('Maximum combined AIOStreams requests per minute, per integration. Lower is safer for hosted/shared instances.')),
                                        TextInput::make('aiostreams_max_failover_candidates')
                                            ->label(__('Max Failover Candidates'))
                                            ->placeholder(__('3'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(10)
                                            ->default(3)
                                            ->helperText(__('Number of ranked stream candidates to keep per resolved movie/episode (1 primary + failovers). Higher values mean more resilience to dead links but more streams fetched per resolve.')),
                                    ]),
                            ]),
                        Tab::make(__('MediaFlow Proxy'))
                            ->id('mediaflow')
                            ->icon('heroicon-m-shield-check')
                            ->schema([
                                Section::make(__('MediaFlow Proxy'))
                                    ->description(__('Connect MediaFlow Proxy to route your playlists, EPG, and Xtream API through it. Once configured, proxied URLs are auto-generated on each playlist\'s detail page.'))
                                    ->columnSpan('full')
                                    ->icon('heroicon-m-shield-check')
                                    ->collapsible()
                                    ->columns(3)
                                    ->headerActions([
                                        Action::make('test_mediaflow_connection')
                                            ->label(__('Test Connection'))
                                            ->icon('heroicon-o-signal')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->action(function ($get): void {
                                                $proxyUrl = rtrim($get('mediaflow_proxy_url') ?? '', '/');
                                                $port = $get('mediaflow_proxy_port');
                                                $password = $get('mediaflow_proxy_password');

                                                if (empty($proxyUrl)) {
                                                    Notification::make()
                                                        ->danger()
                                                        ->title(__('Proxy URL Required'))
                                                        ->body(__('Please enter a MediaFlow Proxy URL to test the connection.'))
                                                        ->send();

                                                    return;
                                                }

                                                if ($port) {
                                                    $proxyUrl .= ':'.$port;
                                                }

                                                try {
                                                    $response = Http::timeout(10)->get($proxyUrl.'/proxy/ip', array_filter([
                                                        'api_password' => $password ?: null,
                                                    ]));

                                                    if ($response->successful()) {
                                                        $ip = $response->json('ip') ?? $response->body();
                                                        Notification::make()
                                                            ->success()
                                                            ->title(__('Connection Successful'))
                                                            ->body(__('MediaFlow Proxy is reachable. Public IP: ').$ip)
                                                            ->send();
                                                    } else {
                                                        $error = $response->json('detail') ?? $response->json('message') ?? "HTTP {$response->status()}";
                                                        Notification::make()
                                                            ->danger()
                                                            ->title(__('Connection Failed'))
                                                            ->body("MediaFlow Proxy returned an error: {$error}")
                                                            ->send();
                                                    }
                                                } catch (Exception $e) {
                                                    Notification::make()
                                                        ->danger()
                                                        ->title(__('Connection Failed'))
                                                        ->body('Could not reach MediaFlow Proxy: '.$e->getMessage())
                                                        ->send();
                                                }
                                            }),
                                        Action::make('mfproxy_docs')
                                            ->label(__('Docs'))
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('https://mhdzumair.github.io/mediaflow-proxy/')
                                            ->openUrlInNewTab(true),
                                        Action::make('mfproxy_git')
                                            ->label(__('GitHub'))
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('https://github.com/mhdzumair/mediaflow-proxy')
                                            ->openUrlInNewTab(true),
                                    ])
                                    ->schema([
                                        TextInput::make('mediaflow_proxy_url')
                                            ->label(__('Proxy URL'))
                                            ->columnSpan(1)
                                            ->placeholder(__('http://your-mediaflow-host:8888')),
                                        TextInput::make('mediaflow_proxy_port')
                                            ->label(__('Proxy Port (Alternative)'))
                                            ->numeric()
                                            ->columnSpan(1)
                                            ->helperText(__('Alternative port if not specified in the URL.')),
                                        TextInput::make('mediaflow_proxy_password')
                                            ->label(__('API Password'))
                                            ->columnSpan(1)
                                            ->password()
                                            ->revealable()
                                            ->helperText(__('The API_PASSWORD configured on your MediaFlow Proxy instance.')),
                                        Toggle::make('mediaflow_proxy_playlist_user_agent')
                                            ->label(__('Use Proxy User Agent for Playlists (M3U8/MPD)'))
                                            ->inline(false)
                                            ->live()
                                            ->helperText(__('If enabled, the User Agent will also be used for fetching playlist files. Otherwise, the default User Agent is used for playlists.')),
                                        TextInput::make('mediaflow_proxy_user_agent')
                                            ->label(__('Proxy User Agent for Media Streams'))
                                            ->placeholder(__('VLC/3.0.21 LibVLC/3.0.21'))
                                            ->columnSpan(2),
                                        Toggle::make('mediaflow_proxy_rewrite_stream_urls')
                                            ->label(__('Automatically Rewrite Stream URLs'))
                                            ->inline(false)
                                            ->columnSpanFull()
                                            ->helperText(__('When enabled, individual stream URLs in generated playlists and Xtream API responses will be rewritten to route through MediaFlow Proxy. Applies only when the m3u-proxy is not already in use for a given playlist or stream.')),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
