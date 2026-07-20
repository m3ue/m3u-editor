<?php

namespace App\Filament\Support;

use App\Enums\DvrSeriesMode;
use App\Models\MediaServerIntegration;
use App\Settings\GeneralSettings;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

/**
 * Shared DVR / Requests / AIOStreams tabs used by PlaylistResource, CustomPlaylistResource,
 * and MergedPlaylistResource. All three playlist types own a DvrSetting, a
 * PlaylistRequestSetting, and an aiostreams_integration_id column, so the tabs and
 * their dvr_/request_ prefixed virtual fields are identical across resources —
 * only the hydrate/save wiring (HasDvrAndRequestFormHooks on the Edit page) differs.
 */
class DvrRequestsAiostreamsTabs
{
    public static function dvrTab(): ?Tab
    {
        if (! auth()->user()?->canUseDvr()) {
            return null;
        }

        return Tab::make(__('DVR'))
            ->icon('heroicon-m-video-camera')
            ->schema([
                Section::make(__('DVR Settings'))
                    ->icon('heroicon-m-video-camera')
                    ->description(__('Configure digital video recording for this playlist. Enable DVR to schedule recordings from the EPG guide.'))
                    ->schema([
                        Toggle::make('dvr_enabled')
                            ->label(__('Enable DVR'))
                            ->helperText(__('When enabled, the EPG guide will show record buttons and the scheduler will process recording rules.'))
                            ->default(false)
                            ->inline(false)
                            ->live(),
                        Select::make('dvr_output_format')
                            ->label(__('Output Format'))
                            ->helperText(__('Container format for the final recording file. All options use stream copy (no re-encoding) — only the container changes.'))
                            ->options([
                                'ts' => 'MPEG-TS (.ts) — fastest, direct segment join, no remuxing',
                                'mp4' => 'MP4 (.mp4) — best compatibility with media players',
                                'mkv' => 'MKV (.mkv) — flexible container, good player support',
                            ])
                            ->default('ts')
                            ->required()
                            ->hidden(fn (Get $get): bool => ! $get('dvr_enabled')),
                        Grid::make()
                            ->columns(2)
                            ->columnSpanFull()
                            ->hidden(fn (Get $get): bool => ! $get('dvr_enabled'))
                            ->schema([
                                TextInput::make('dvr_max_concurrent_recordings')
                                    ->label(__('Max Concurrent Recordings'))
                                    ->helperText(__('Maximum number of recordings that can run simultaneously for this playlist.'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(20)
                                    ->default(2),
                                TextInput::make('dvr_default_start_early_seconds')
                                    ->label(__('Start Early (seconds)'))
                                    ->helperText(__('Start recordings this many seconds before the scheduled start time.'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(600)
                                    ->default(30),
                                TextInput::make('dvr_default_end_late_seconds')
                                    ->label(__('End Late (seconds)'))
                                    ->helperText(__('Continue recording this many seconds after the scheduled end time.'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(600)
                                    ->default(60),
                                TextInput::make('dvr_retention_days')
                                    ->label(__('Retention (days)'))
                                    ->helperText(__('Automatically delete recordings older than this many days. Set to 0 to disable automatic deletion.'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0),
                                TextInput::make('dvr_global_disk_quota_gb')
                                    ->label(__('Disk Quota (GB)'))
                                    ->helperText(__('Maximum total disk usage for DVR recordings. Oldest recordings are deleted first when quota is exceeded. Set to 0 for no limit.'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0),
                            ]),
                        Grid::make()
                            ->columns(2)
                            ->columnSpanFull()
                            ->hidden(fn (Get $get): bool => ! $get('dvr_enabled'))
                            ->schema([
                                Toggle::make('dvr_enable_metadata_enrichment')
                                    ->label(__('Enable Metadata Enrichment'))
                                    ->helperText(__('Automatically fetch metadata (artwork, descriptions, episode info) from TMDB and TVMaze after recording.'))
                                    ->default(true)
                                    ->inline(false)
                                    ->live(),
                                Toggle::make('dvr_enable_comskip')
                                    ->label(__('Enable Commercial Detection (Comskip)'))
                                    ->helperText(new HtmlString(__('Run comskip after recording to detect and mark commercials. Produces .edl files that Kodi, Jellyfin, and Emby can use for automatic commercial skipping. The Emby.ComSkiper plugin for Emby is available at <a href="https://github.com/BillOatmanWork/Emby.ComSkipper" class="underline" target="_blank" rel="noopener">GitHub/BillOatmanWork/Emby.ComSkipper</a>.')))
                                    ->default(false)
                                    ->inline(false)
                                    ->columnSpanFull(),
                                Placeholder::make('dvr_tmdb_status')
                                    ->label(__('TMDB'))
                                    ->content(function (): HtmlString {
                                        $hasKey = ! empty(app(GeneralSettings::class)->tmdb_api_key);

                                        if ($hasKey) {
                                            return new HtmlString(
                                                '<span class="text-sm text-success-600 dark:text-success-400 font-medium">✓ TMDB API key configured in Settings</span>'
                                            );
                                        }

                                        $url = route('filament.admin.pages.preferences').'#tmdb';

                                        return new HtmlString(
                                            '<span class="text-sm text-warning-600 dark:text-warning-400">No TMDB API key found. '
                                            .'<a href="'.e($url).'" class="underline font-medium">Configure it in Settings → TMDB</a> '
                                            .'to enable TMDB metadata lookups. TVMaze will be used as a fallback.</span>'
                                        );
                                    })
                                    ->hidden(fn (Get $get): bool => ! $get('dvr_enable_metadata_enrichment')),
                                Toggle::make('dvr_generate_nfo_files')
                                    ->label(__('Generate NFO Files'))
                                    ->helperText(__('Write Kodi/Jellyfin/Plex compatible .nfo metadata files alongside each recording (uses enriched TMDB/TVMaze metadata).'))
                                    ->default(false)
                                    ->inline(false)
                                    ->columnSpanFull(),
                                Toggle::make('dvr_include_disabled_channels')
                                    ->label(__('Show Disabled Channels in Browse Shows'))
                                    ->helperText(__('When enabled, Browse Shows will include content from channels that are disabled. Allows scheduling recordings for content on non-enabled channels.'))
                                    ->default(false)
                                    ->inline(false),
                            ]),
                    ]),
                Section::make(__('Series Recording Defaults'))
                    ->icon('heroicon-m-film')
                    ->description(__('Default settings applied when creating new series recording rules for this playlist. These can be overridden per-rule in the rule\'s advanced settings.'))
                    ->hidden(fn (Get $get): bool => ! $get('dvr_enabled'))
                    ->schema([
                        Grid::make()
                            ->columns(2)
                            ->columnSpanFull()
                            ->schema([
                                Select::make('dvr_default_series_mode')
                                    ->label(__('Record Episodes (Default)'))
                                    ->helperText(__('Default recording strategy for new series rules. "Unique by Season & Episode" avoids re-recording episodes you already have.'))
                                    ->options(DvrSeriesMode::class)
                                    ->default(DvrSeriesMode::UniqueSe->value)
                                    ->required(),
                                TextInput::make('dvr_default_series_keep_last')
                                    ->label(__('Keep Last N Recordings (Default)'))
                                    ->helperText(__('Default number of recordings to keep per series. Leave blank to keep all recordings.'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->placeholder(__('Keep all')),
                            ]),
                    ]),
            ])
            ->hiddenOn('create');
    }

    public static function requestsTab(): Tab
    {
        return Tab::make(__('Requests'))
            ->icon('heroicon-m-squares-plus')
            ->hidden(fn () => ! auth()->user()->canUseIntegrations())
            ->schema([
                Section::make(__('Content Requests'))
                    ->icon('heroicon-m-squares-plus')
                    ->description(__('Allow guests to browse and request content from your Sonarr and Radarr servers on this playlist.'))
                    ->schema([
                        Toggle::make('request_enabled')
                            ->label(__('Enable Content Requests'))
                            ->helperText(__('When enabled, guests on this playlist will see the Request Content page and can submit requests to your configured Sonarr/Radarr integrations.'))
                            ->default(false)
                            ->inline(false),
                    ]),
            ])
            ->hiddenOn('create');
    }

    public static function aiostreamsTab(): Tab
    {
        $aiostreamsOptions = fn () => MediaServerIntegration::where('user_id', auth()->id())
            ->where('type', 'aiostreams')
            ->where('enabled', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();

        return Tab::make(__('AIOStreams'))
            ->icon('heroicon-m-film')
            ->hidden(fn () => MediaServerIntegration::where('user_id', auth()->id())
                ->where('type', 'aiostreams')
                ->where('enabled', true)
                ->doesntExist()
            )
            ->schema([
                Section::make(__('AIOStreams Access'))
                    ->icon('heroicon-m-film')
                    ->description(__('Grant guests on this playlist access to an AIOStreams on-demand catalog. Guests authenticated via Playlist Auth also need AIOStreams enabled on their auth profile.'))
                    ->schema([
                        Select::make('aiostreams_integration_id')
                            ->label(__('AIOStreams Integration'))
                            ->options($aiostreamsOptions)
                            ->placeholder(__('No AIOStreams access'))
                            ->nullable()
                            ->helperText(__('Select which AIOStreams integration to expose on this playlist. Leave blank to disable AIOStreams for this playlist.')),
                    ]),
            ])
            ->hiddenOn('create');
    }
}
