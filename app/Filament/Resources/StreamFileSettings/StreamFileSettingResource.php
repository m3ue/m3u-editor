<?php

namespace App\Filament\Resources\StreamFileSettings;

use App\Filament\Concerns\HasCopilotSupport;
use App\Models\MediaServerIntegration;
use App\Models\StreamFileSetting;
use App\Rules\CheckIfUrlOrLocalPath;
use App\Services\PlaylistService;
use App\Traits\HasUserFiltering;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StreamFileSettingResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
    use HasUserFiltering;

    protected static ?string $model = StreamFileSetting::class;

    public static function getNavigationGroup(): ?string
    {
        return __('Playlist');
    }

    public static function getNavigationLabel(): string
    {
        return __('Stream File Settings');
    }

    public static function getModelLabel(): string
    {
        return __('Stream File Setting');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stream File Settings');
    }

    /**
     * Check if the user can access this page.
     * Only users with the "stream file sync" permission can access this page.
     */
    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseStreamFileSync();
    }

    public static function getNavigationSort(): ?int
    {
        return 7;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Profile Name'))
                    ->required()
                    ->maxLength(255)
                    ->helperText(__('A descriptive name for this stream file setting profile')),

                Select::make('type')
                    ->label(__('Type'))
                    ->options([
                        'series' => 'Series',
                        'vod' => 'VOD',
                    ])
                    ->required()
                    ->live()
                    ->disabledOn('edit')
                    ->afterStateUpdated(function ($state, callable $set) {
                        if ($state === 'series') {
                            $set('path_structure', ['category', 'series', 'season']);
                            $set('folder_metadata', []);
                        } else {
                            $set('path_structure', ['group', 'title']);
                            $set('folder_metadata', ['year', 'tmdb_id']);
                        }
                        $set('filename_metadata', []);
                    })
                    ->helperText(__('Determines which path structure options are available and where this profile can be assigned')),

                Textarea::make('description')
                    ->label(__('Description'))
                    ->columnSpanFull()
                    ->rows(2)
                    ->maxLength(255)
                    ->helperText(__('Optional description of this profile')),

                Toggle::make('enabled')
                    ->label(__('Enable .strm file generation'))
                    ->default(true)
                    ->columnSpanFull()
                    ->live(),

                Select::make('url_type')
                    ->label(__('URL Type'))
                    ->options([
                        'proxy' => 'M3U Editor (default)',
                        'original' => 'Original Source URL',
                    ])
                    ->default('proxy')
                    ->native(false)
                    ->columnSpanFull()
                    ->hintIcon(
                        'heroicon-s-information-circle',
                        tooltip: 'When routing through M3U Editor, the generated .strm files will use URLs that point to the editor, which then proxies the request to the original media source, or redirects to the original source if proxy is disabled. Use original source URLs if your media server can access the original media source directly and you want to avoid the extra hop through m3u-editor. Note that using original source URLs may expose the location of your media files to clients, so ensure that your media server is properly secured if you choose this option.',
                    )
                    ->helperText(__('Default routes through M3U Editor for dynamic routing.')),

                TextInput::make('location')
                    ->label(__('Sync Location'))
                    ->rules([new CheckIfUrlOrLocalPath(localOnly: true, isDirectory: true)])
                    ->required()
                    ->columnSpanFull()
                    ->helperText(__('Local directory path where .strm files will be written.'))
                    ->hidden(fn ($get) => ! $get('enabled'))
                    ->placeholder(fn ($get) => $get('type') === 'series' ? '/Series' : '/Movies'),

                // Preview section with dynamic content based on form state
                Section::make(__('Path Preview'))
                    ->compact()
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('path_preview')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'font-mono'])
                            ->content(function (Get $get): string {
                                $type = $get('type');

                                $map = fn ($char) => match ($char) {
                                    'space' => ' ',
                                    'dash' => '-',
                                    'underscore' => '_',
                                    'period' => '.',
                                    'remove' => '',
                                    default => $char,
                                };

                                if ($type === 'vod') {
                                    $vod = PlaylistService::getVodExample();

                                    $path = $get('location') ?? '';
                                    $pathStructure = $get('path_structure') ?? [];
                                    $filenameMetadata = $get('filename_metadata') ?? [];
                                    $folderMetadata = $get('folder_metadata') ?? [];
                                    $tmdbIdFormat = $get('tmdb_id_format') ?? 'square';
                                    $replaceChar = $map($get('replace_char') ?? 'space');
                                    $titleFolderEnabled = in_array('title', $pathStructure);

                                    $preview = $path;

                                    if (in_array('group', $pathStructure)) {
                                        $groupName = $vod->group->name ?? $vod->group ?? 'Uncategorized';
                                        $preview .= '/'.PlaylistService::makeFilesystemSafe($groupName, $replaceChar);
                                    }

                                    if ($titleFolderEnabled) {
                                        $titleFolder = PlaylistService::makeFilesystemSafe($vod->title ?? '', $replaceChar);

                                        if (in_array('year', $folderMetadata) && ! empty($vod->year) && strpos($titleFolder, "({$vod->year})") === false) {
                                            $titleFolder .= " ({$vod->year})";
                                        }

                                        if (in_array('tmdb_id', $folderMetadata)) {
                                            $tmdbId = $vod->info['tmdb_id'] ?? $vod->info['tmdb'] ?? $vod->movie_data['tmdb_id'] ?? $vod->movie_data['tmdb'] ?? null;
                                            $imdbId = $vod->info['imdb_id'] ?? $vod->info['imdb'] ?? $vod->movie_data['imdb_id'] ?? $vod->movie_data['imdb'] ?? null;
                                            $bracket = $tmdbIdFormat === 'curly' ? ['{', '}'] : ['[', ']'];
                                            if (! empty($tmdbId)) {
                                                $titleFolder .= " {$bracket[0]}tmdb-{$tmdbId}{$bracket[1]}";
                                            } elseif (! empty($imdbId)) {
                                                $titleFolder .= " {$bracket[0]}imdb-{$imdbId}{$bracket[1]}";
                                            }
                                        }

                                        $preview .= '/'.$titleFolder;
                                    }

                                    $filename = PlaylistService::makeFilesystemSafe($vod->title ?? '', $replaceChar);

                                    if (in_array('year', $filenameMetadata) && ! empty($vod->year)) {
                                        if (strpos($filename, "({$vod->year})") === false) {
                                            $filename .= " ({$vod->year})";
                                        }
                                    }

                                    $tmdbId = $vod->info['tmdb_id'] ?? $vod->info['tmdb'] ?? $vod->movie_data['tmdb_id'] ?? $vod->movie_data['tmdb'] ?? null;
                                    $imdbId = $vod->info['imdb_id'] ?? $vod->info['imdb'] ?? $vod->movie_data['imdb_id'] ?? $vod->movie_data['imdb'] ?? null;
                                    if (in_array('tmdb_id', $filenameMetadata)) {
                                        $bracket = $tmdbIdFormat === 'curly' ? ['{', '}'] : ['[', ']'];
                                        if (! empty($tmdbId)) {
                                            $filename .= " {$bracket[0]}tmdb-{$tmdbId}{$bracket[1]}";
                                        } elseif (! empty($imdbId)) {
                                            $filename .= " {$bracket[0]}imdb-{$imdbId}{$bracket[1]}";
                                        }
                                    }

                                    if (in_array('group', $filenameMetadata)) {
                                        $groupName = $vod->group->name ?? $vod->group ?? 'Uncategorized';
                                        $groupName = PlaylistService::makeFilesystemSafe($groupName, $replaceChar);
                                        $filename .= " - {$groupName}";
                                    }

                                    if ((bool) $get('trash_guide_naming_enabled')) {
                                        $components = $get('trash_movie_components') ?? ['quality', 'video', 'audio', 'hdr'];
                                        $usePlexMarker = (bool) ($get('group_versions') ?? true);
                                        $sample = [
                                            'edition' => 'Directors Cut',
                                            'quality' => '1080p',
                                            'video' => 'x264',
                                            'audio' => 'DTS',
                                            'hdr' => 'HDR10',
                                        ];
                                        if (in_array('edition', $components)) {
                                            $filename .= $usePlexMarker
                                                ? ' {edition-'.str_replace(' ', '.', $sample['edition']).'}'
                                                : ' '.$sample['edition'];
                                        }
                                        $bracketParts = [];
                                        foreach (['quality', 'video', 'audio', 'hdr'] as $b) {
                                            if (in_array($b, $components) && $sample[$b] !== '') {
                                                $bracketParts[] = $sample[$b];
                                            }
                                        }
                                        if ($bracketParts) {
                                            $filename .= ' ['.implode(' ', $bracketParts).']';
                                        }
                                        $filename = trim(preg_replace('/\s+/', ' ', $filename));
                                    }

                                    $preview .= '/'.$filename.'.strm';

                                    return $preview;
                                }

                                // Default to series preview
                                $series = PlaylistService::getEpisodeExample();

                                $path = $get('location') ?? '';
                                $pathStructure = $get('path_structure') ?? [];
                                $filenameMetadata = $get('filename_metadata') ?? [];
                                $tmdbIdFormat = $get('tmdb_id_format') ?? 'square';
                                $tmdbIdApplyTo = $get('tmdb_id_apply_to') ?? 'episodes';
                                $replaceChar = $map($get('replace_char') ?? 'space');

                                $preview = $path;

                                if (in_array('category', $pathStructure)) {
                                    $preview .= '/'.($series->category ?? 'Uncategorized');
                                }
                                if (in_array('series', $pathStructure)) {
                                    $seriesFolder = $series->series->metadata['name'] ?? $series->series->name ?? 'Series';

                                    if (! empty($series->series->release_date ?? null)) {
                                        $year = substr($series->series->release_date, 0, 4);
                                        if (strpos($seriesFolder, "({$year})") === false) {
                                            $seriesFolder .= " ({$year})";
                                        }
                                    }

                                    $tvdbId = $series->series->tvdb_id ?? $series->series->metadata['tvdb_id'] ?? $series->series->metadata['tvdb'] ?? null;
                                    $tmdbId = $series->series->tmdb_id ?? $series->series->metadata['tmdb_id'] ?? $series->series->metadata['tmdb'] ?? null;
                                    $imdbId = $series->series->imdb_id ?? $series->series->metadata['imdb_id'] ?? $series->series->metadata['imdb'] ?? null;
                                    $tmdbEnabled = in_array('tmdb_id', $filenameMetadata, true);
                                    $applyTmdbToSeriesFolder = $tmdbEnabled && in_array($tmdbIdApplyTo, ['series', 'both'], true);
                                    $bracket = $tmdbIdFormat === 'curly' ? ['{', '}'] : ['[', ']'];

                                    if ($applyTmdbToSeriesFolder) {
                                        if (! empty($tmdbId)) {
                                            $seriesFolder .= " {$bracket[0]}tmdb-{$tmdbId}{$bracket[1]}";
                                        } elseif (! empty($tvdbId)) {
                                            $seriesFolder .= " {$bracket[0]}tvdb-{$tvdbId}{$bracket[1]}";
                                        } elseif (! empty($imdbId)) {
                                            $seriesFolder .= " {$bracket[0]}imdb-{$imdbId}{$bracket[1]}";
                                        }
                                    } elseif (! empty($tvdbId)) {
                                        $seriesFolder .= " {$bracket[0]}tvdb-{$tvdbId}{$bracket[1]}";
                                    } elseif (! empty($imdbId)) {
                                        $seriesFolder .= " {$bracket[0]}imdb-{$imdbId}{$bracket[1]}";
                                    }

                                    $preview .= '/'.$seriesFolder;
                                }
                                if (in_array('season', $pathStructure)) {
                                    $preview .= '/Season '.str_pad($series->info->season ?? 0, 2, '0', STR_PAD_LEFT);
                                }

                                $season = str_pad($series->info->season ?? 0, 2, '0', STR_PAD_LEFT);
                                $episode = str_pad($series->episode_num ?? 0, 2, '0', STR_PAD_LEFT);
                                $filename = PlaylistService::makeFilesystemSafe("S{$season}E{$episode} - ".($series->title ?? ''), $replaceChar);

                                if (in_array('year', $filenameMetadata) && ! empty($series->series->release_date ?? null)) {
                                    $year = substr($series->series->release_date, 0, 4);
                                    $filename .= " ({$year})";
                                }
                                $tmdbEnabled = in_array('tmdb_id', $filenameMetadata, true);
                                $applyTmdbToEpisodes = $tmdbEnabled && in_array($tmdbIdApplyTo, ['episodes', 'both'], true);
                                if ($applyTmdbToEpisodes && ! empty($series->info->tmdb_id ?? null)) {
                                    $bracket = $tmdbIdFormat === 'curly' ? ['{', '}'] : ['[', ']'];
                                    $filename .= " {$bracket[0]}tmdb-{$series->info->tmdb_id}{$bracket[1]}";
                                }

                                if (in_array('category', $filenameMetadata)) {
                                    $catName = $series->category ?? 'Uncategorized';
                                    $catName = PlaylistService::makeFilesystemSafe($catName, $replaceChar);
                                    $filename .= " - {$catName}";
                                }

                                if ((bool) $get('trash_guide_naming_enabled')) {
                                    $components = $get('trash_episode_components') ?? ['quality', 'video', 'audio', 'hdr'];
                                    $sample = [
                                        'quality' => '1080p',
                                        'video' => 'x264',
                                        'audio' => 'DDP5.1',
                                        'hdr' => '',
                                    ];
                                    $bracketParts = [];
                                    foreach (['quality', 'video', 'audio', 'hdr'] as $b) {
                                        if (in_array($b, $components) && $sample[$b] !== '') {
                                            $bracketParts[] = $sample[$b];
                                        }
                                    }
                                    if ($bracketParts) {
                                        $filename .= ' ['.implode(' ', $bracketParts).']';
                                    }
                                    $filename = trim(preg_replace('/\s+/', ' ', $filename));
                                }

                                $preview .= '/'.$filename.'.strm';

                                return $preview;
                            }),
                    ])
                    ->hidden(fn ($get) => ! $get('enabled')),

                ToggleButtons::make('path_structure')
                    ->label(__('Path structure (folders)'))
                    ->live()
                    ->multiple()
                    ->grouped()
                    ->options(fn ($get) => $get('type') === 'series'
                        ? ['category' => 'Category', 'series' => 'Series', 'season' => 'Season']
                        : ['group' => 'Group', 'title' => 'Title']
                    )
                    ->default(fn ($get) => $get('type') === 'series'
                        ? ['category', 'series', 'season']
                        : ['group', 'title']
                    )
                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                        if ($get('type') !== 'vod') {
                            return;
                        }
                        if (in_array('title', $state ?? [])) {
                            if (empty($get('folder_metadata'))) {
                                $set('folder_metadata', ['year', 'tmdb_id']);
                            }
                        } else {
                            $set('folder_metadata', []);
                        }
                    })
                    ->hidden(fn ($get) => ! $get('enabled')),

                Fieldset::make(__('Include Metadata'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('folder_metadata')
                            ->label(__('Title folder metadata'))
                            ->helperText(__('Added to the title folder name'))
                            ->live()
                            ->inline()
                            ->multiple()
                            ->options(['year' => 'Year', 'tmdb_id' => 'TMDB ID'])
                            ->default(['year', 'tmdb_id'])
                            ->visible(fn (Get $get): bool => $get('type') === 'vod' && in_array('title', $get('path_structure') ?? [])),
                        ToggleButtons::make('filename_metadata')
                            ->label(__('Filename metadata'))
                            ->live()
                            ->inline()
                            ->multiple()
                            ->options(fn (Get $get): array => match (true) {
                                $get('type') === 'series' => [
                                    'year' => 'Year',
                                    'tmdb_id' => 'TMDB ID',
                                    'category' => 'Category',
                                ],
                                $get('type') === 'vod' && in_array('title', $get('path_structure') ?? []) => [
                                    'year' => 'Year',
                                    'group' => 'Group',
                                ],
                                default => [
                                    'year' => 'Year',
                                    'tmdb_id' => 'TMDB ID',
                                    'group' => 'Group',
                                ],
                            }),
                        ToggleButtons::make('tmdb_id_format')
                            ->label(__('TMDB ID format'))
                            ->inline()
                            ->grouped()
                            ->live()
                            ->options([
                                'square' => '[square]',
                                'curly' => '{curly}',
                            ])
                            ->default('square')
                            ->hidden(fn (Get $get): bool => ! in_array('tmdb_id', $get('filename_metadata') ?? [])
                                && ! in_array('tmdb_id', $get('folder_metadata') ?? [])),
                        ToggleButtons::make('tmdb_id_apply_to')
                            ->label(__('Apply TMDB ID to'))
                            ->inline()
                            ->grouped()
                            ->live()
                            ->options([
                                'episodes' => 'Episodes',
                                'series' => 'Series folder',
                                'both' => 'Both',
                            ])
                            ->default('episodes')
                            ->helperText(__('How should the TMDB ID be used.'))
                            ->hidden(fn (Get $get): bool => $get('type') !== 'series' || ! in_array('tmdb_id', $get('filename_metadata') ?? [])),

                        // ── Trash Guide naming (extra components appended to the standard filename) ──
                        Toggle::make('trash_guide_naming_enabled')
                            ->label(__('Enable Trash Guide naming (extra components)'))
                            ->default(false)
                            ->inline(false)
                            ->live()
                            ->columnSpanFull()
                            ->helperText(__('When enabled, appends edition + a quality/codec/audio/HDR bracket to the filename built from "Filename metadata" above. Both work together.')),
                        Placeholder::make('trash_probe_hint')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => (bool) $get('trash_guide_naming_enabled'))
                            ->content(__('Note: Quality, video codec, audio and HDR placeholders require stream probing. Channels/episodes that have not been probed will fall back to manual values from the playlist source — some placeholders may render empty.')),
                        ToggleButtons::make('trash_movie_components')
                            ->label(__('Movie extra components'))
                            ->helperText(__('Appended to the standard filename. Title/Year/TMDB/Group come from "Filename metadata" above.'))
                            ->live()
                            ->inline()
                            ->multiple()
                            ->columnSpanFull()
                            ->options([
                                'edition' => 'Edition',
                                'quality' => 'Quality',
                                'video' => 'Video',
                                'audio' => 'Audio',
                                'hdr' => 'HDR',
                            ])
                            ->default(['quality', 'video', 'audio', 'hdr'])
                            ->visible(fn (Get $get): bool => $get('type') === 'vod' && (bool) $get('trash_guide_naming_enabled')),
                        ToggleButtons::make('trash_episode_components')
                            ->label(__('Episode extra components'))
                            ->helperText(__('Appended to the standard episode filename (Series, Season/Episode, Episode title come from the standard naming).'))
                            ->live()
                            ->inline()
                            ->multiple()
                            ->columnSpanFull()
                            ->options([
                                'quality' => 'Quality',
                                'video' => 'Video',
                                'audio' => 'Audio',
                                'hdr' => 'HDR',
                            ])
                            ->default(['quality', 'video', 'audio', 'hdr'])
                            ->visible(fn (Get $get): bool => $get('type') === 'series' && (bool) $get('trash_guide_naming_enabled')),
                        Toggle::make('group_versions')
                            ->label(__('Use Plex/Jellyfin/Emby multi-version markers'))
                            ->default(true)
                            ->inline(false)
                            ->live()
                            ->columnSpanFull()
                            ->helperText(__('When enabled, the edition is written as {edition-Name} (Plex marker). All versions of a movie land in the same folder so media servers offer a version switch (e.g. 1080p / 4K / Director\'s Cut). When disabled, the edition is written as a plain suffix.'))
                            ->visible(fn (Get $get): bool => $get('type') === 'vod'
                                && (bool) $get('trash_guide_naming_enabled')
                                && in_array('edition', $get('trash_movie_components') ?? [], true)),
                    ])
                    ->hidden(fn ($get) => ! $get('enabled')),

                Fieldset::make(__('Filename Cleansing'))
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('clean_special_chars')
                            ->label(__('Clean special characters'))
                            ->helperText(__('Remove or replace special characters in filenames'))
                            ->default(true)
                            ->inline(false),
                        Toggle::make('remove_consecutive_chars')
                            ->label(__('Remove consecutive replacement characters'))
                            ->default(true)
                            ->inline(false),
                        ToggleButtons::make('replace_char')
                            ->label(__('Replace with'))
                            ->live()
                            ->inline()
                            ->grouped()
                            ->columnSpanFull()
                            ->options([
                                'space' => 'Space',
                                'dash' => '-',
                                'underscore' => '_',
                                'period' => '.',
                                'remove' => 'Remove',
                            ])
                            ->default('space'),
                    ])
                    ->hidden(fn ($get) => ! $get('enabled')),

                Fieldset::make(__('Name Filtering'))
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('name_filter_enabled')
                            ->label(__('Enable name filtering'))
                            ->helperText(__('Remove specific words or symbols from folder and file names'))
                            ->inline(false)
                            ->live(),
                        Forms\Components\TagsInput::make('name_filter_patterns')
                            ->label(__('Patterns to remove'))
                            ->placeholder(__('Add pattern (e.g. "DE • " or "EN |")'))
                            ->helperText(__('Enter words, symbols or prefixes to remove. Press Enter after each pattern.'))
                            ->columnSpanFull()
                            ->hidden(fn ($get) => ! $get('name_filter_enabled')),
                    ])
                    ->hidden(fn ($get) => ! $get('enabled')),

                Fieldset::make(__('NFO File Generation'))
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('generate_nfo')
                            ->label(__('Generate NFO files'))
                            ->helperText(fn ($get) => $get('type') === 'series'
                                ? 'Create tvshow.nfo and episode.nfo files for Kodi, Emby, and Jellyfin compatibility'
                                : 'Create movie.nfo files for Kodi, Emby, and Jellyfin compatibility'
                            )
                            ->inline(false),
                    ])
                    ->hidden(fn ($get) => ! $get('enabled')),

                Fieldset::make(__('Media Server Library Refresh'))
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('refresh_media_server')
                            ->label(__('Refresh media server library after sync'))
                            ->helperText(__('Automatically trigger a library scan on your media server after .strm files are synced'))
                            ->inline(false)
                            ->live(),
                        Select::make('media_server_integration_id')
                            ->label(__('Media Server'))
                            ->options(fn () => MediaServerIntegration::query()
                                ->where('user_id', auth()->id())
                                ->whereIn('type', ['jellyfin', 'emby', 'plex'])
                                ->pluck('name', 'id')
                            )
                            ->searchable()
                            ->required()
                            ->helperText(__('Select which media server to refresh (Jellyfin, Emby, or Plex)'))
                            ->hidden(fn ($get) => ! $get('refresh_media_server')),
                        TextInput::make('refresh_delay_seconds')
                            ->label(__('Delay before refresh (seconds)'))
                            ->numeric()
                            ->default(5)
                            ->minValue(0)
                            ->maxValue(300)
                            ->helperText(__('Wait this many seconds after sync completes before triggering the library refresh'))
                            ->hidden(fn ($get) => ! $get('refresh_media_server')),
                    ])
                    ->hidden(fn ($get) => ! $get('enabled')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->persistSortInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->modifyQueryUsing(function ($query) {
                $query->withCount(['series', 'channels', 'groups', 'categories']);
            })
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->colors([
                        'primary' => 'series',
                        'success' => 'vod',
                    ])
                    ->sortable(),
                TextColumn::make('location')
                    ->label(__('Location'))
                    ->limit(30)
                    ->toggleable(),
                ToggleColumn::make('enabled')
                    ->label(__('Enabled')),
                TextColumn::make('series_count')
                    ->label(__('Series'))
                    ->counts('series')
                    ->toggleable(),
                TextColumn::make('channels_count')
                    ->label(__('VOD'))
                    ->counts('channels')
                    ->toggleable(),
                TextColumn::make('groups_count')
                    ->label(__('Groups'))
                    ->counts('groups')
                    ->toggleable(),
                TextColumn::make('categories_count')
                    ->label(__('Categories'))
                    ->counts('categories')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'series' => 'Series',
                        'vod' => 'VOD',
                    ]),
            ])
            ->recordActions([
                Actions\DeleteAction::make()
                    ->button()->hiddenLabel()->size('sm'),
                Actions\EditAction::make()
                    ->slideOver()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListStreamFileSettings::route('/'),
        ];
    }
}
