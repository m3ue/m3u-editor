<?php

namespace App\Filament\Resources\StreamProfiles;

use App\Filament\Concerns\HasCopilotSupport;
use App\Models\StreamProfile;
use App\Services\M3uProxyService;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class StreamProfileResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;

    protected static ?string $model = StreamProfile::class;

    public static function getNavigationGroup(): ?string
    {
        return __('Proxy');
    }

    public static function getModelLabel(): string
    {
        return __('Stream Profile');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stream Profiles');
    }

    /**
     * Check if the user can access this resource.
     * Only users with proxy permission can access stream profiles.
     */
    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseProxy();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Profile Name'))
                    ->required()
                    ->columnSpanFull()
                    ->maxLength(255)
                    ->helperText(__('A descriptive name for this profile (e.g., "720p Standard", "Twitch Stream")')),

                Textarea::make('description')
                    ->label(__('Description'))
                    ->columnSpanFull()
                    ->rows(2)
                    ->maxLength(255)
                    ->helperText(__('Optional description of what this profile does')),

                Select::make('backend')
                    ->label(__('Stream Backend'))
                    ->options([
                        'ffmpeg' => 'FFmpeg (transcoding)',
                        'streamlink' => 'Streamlink',
                        'ytdlp' => 'yt-dlp',
                        'adaptive' => 'Adaptive (rule-based)',
                    ])
                    ->searchable()
                    ->default('ffmpeg')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        // Keep user-entered values, but replace untouched defaults
                        // when switching backend during profile creation/editing.
                        $currentArgs = trim((string) ($get('args') ?? ''));

                        $knownDefaults = [
                            self::defaultArgsForBackend('ffmpeg'),
                            self::defaultArgsForBackend('streamlink'),
                            self::defaultArgsForBackend('ytdlp'),
                        ];

                        if ($currentArgs === '' || in_array($currentArgs, $knownDefaults, true)) {
                            $set('args', self::defaultArgsForBackend($state));
                        }
                    })
                    ->columnSpanFull()
                    ->helperText(__('FFmpeg re-encodes the stream. Streamlink and yt-dlp extract and deliver streams directly from supported platforms (Twitch, YouTube, etc.) without re-encoding. Adaptive delegates to one of your existing transcoding profiles based on probed channel metadata.')),

                Textarea::make('args')
                    ->label(fn (Get $get): string => match ($get('backend')) {
                        'streamlink' => __('Quality & Options'),
                        'ytdlp' => __('Format Selector & Options'),
                        default => __('FFmpeg Template'),
                    })
                    ->required(fn (Get $get): bool => $get('backend') !== 'adaptive')
                    ->visible(fn (Get $get): bool => $get('backend') !== 'adaptive')
                    ->columnSpanFull()
                    ->rows(4)
                    ->hintAction(
                        fn (Get $get) => Action::make('view_profile_docs')
                            ->label(__('View Docs'))
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->iconPosition('after')
                            ->size('sm')
                            ->url(match ($get('backend')) {
                                'streamlink' => 'https://streamlink.github.io/cli.html',
                                'ytdlp' => 'https://github.com/yt-dlp/yt-dlp#usage-and-options',
                                default => 'https://m3ue.sparkison.dev/docs/proxy/transcoding',
                            })
                            ->openUrlInNewTab(true)
                    )
                    ->default(fn (Get $get): string => self::defaultArgsForBackend($get('backend')))
                    ->placeholder(fn (Get $get): string => self::defaultArgsForBackend($get('backend')))
                    ->helperText(fn (Get $get): string => match ($get('backend')) {
                        'streamlink' => __('Quality selector (best, worst, 720p, etc.) followed by optional Streamlink flags. Example: best --hls-live-edge 3'),
                        'ytdlp' => __('yt-dlp format selector followed by optional flags. Example: bestvideo+bestaudio/best --no-playlist'),
                        default => __('FFmpeg arguments for transcoding. Use placeholders like {crf|23} for configurable parameters with defaults. Hardware acceleration will be applied automatically by the proxy server.'),
                    }),

                TextInput::make('cookies_path')
                    ->label(__('Cookies File Path'))
                    ->placeholder('/app/cookies/cookies.txt')
                    ->helperText(__('Absolute path to a Netscape-format cookies.txt file on the proxy host. Mount the file into the proxy container and enter its container path here.'))
                    ->columnSpanFull()
                    ->hintAction(
                        Action::make('verify_cookies_path')
                            ->label(__('Verify'))
                            ->icon('heroicon-o-check-circle')
                            ->action(function (Get $get) {
                                $path = $get('cookies_path');

                                if (empty($path)) {
                                    Notification::make()
                                        ->title(__('No path entered'))
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                $result = app(M3uProxyService::class)->validateCookiesFilePath($path);

                                if ($result['valid']) {
                                    Notification::make()
                                        ->title(__('File verified'))
                                        ->body($result['message'])
                                        ->success()
                                        ->send();
                                } else {
                                    Notification::make()
                                        ->title(__('Verification failed'))
                                        ->body($result['message'])
                                        ->danger()
                                        ->send();
                                }
                            })
                    )
                    ->visible(fn (Get $get): bool => in_array($get('backend'), ['streamlink', 'ytdlp'])),

                Select::make('format')
                    ->label(__('Stream Format'))
                    ->searchable()
                    ->options([
                        'mp4' => 'MP4 (.mp4)',
                        'mov' => 'MOV (.mov)',
                        'mkv' => 'Matroska (.mkv)',
                        'webm' => 'WebM (.webm)',
                        'ts' => 'MPEG-TS (.ts)',
                        'mpeg' => 'MPEG (.mpeg)',
                        'm3u8' => 'HLS (.m3u8)',
                        'flv' => 'FLV (.flv)',
                        'ogg' => 'OGG (.ogg)',
                        'mp3' => 'MP3 (.mp3)',
                    ])
                    ->default('ts')
                    ->required(fn (Get $get): bool => $get('backend') !== 'adaptive')
                    ->visible(fn (Get $get): bool => $get('backend') !== 'adaptive')
                    ->helperText(fn (Get $get): string => match ($get('backend')) {
                        'streamlink' => __('The container format Streamlink will output. This sets the URL extension (e.g. .ts, .mp4) so players know how to handle the stream. Must match the format Streamlink actually produces for the selected quality.'),
                        'ytdlp' => __('The container format yt-dlp will output. This sets the URL extension (e.g. .ts, .mp4) so players know how to handle the stream. Must match the format produced by your yt-dlp format selector.'),
                        default => __('The container format FFmpeg will produce. Must match the -f muxer argument in your FFmpeg template above.'),
                    }),

                Section::make(__('Adaptive rules'))
                    ->description(__('Pick a transcoding profile based on probed channel metadata. Rules are evaluated top to bottom; the first matching rule wins. Conditions inside a rule are ANDed; to express OR, add another rule pointing at the same profile.'))
                    ->visible(fn (Get $get): bool => $get('backend') === 'adaptive')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('rules')
                            ->label(__('Rules'))
                            ->required(fn (Get $get): bool => $get('backend') === 'adaptive')
                            ->minItems(1)
                            ->reorderable(true)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => self::summarizeRule($state))
                            ->addActionLabel(__('Add rule'))
                            ->schema([
                                Repeater::make('conditions')
                                    ->label(__('All of these conditions must be true (AND)'))
                                    ->minItems(1)
                                    ->reorderable(false)
                                    ->addActionLabel(__('Add condition'))
                                    ->columns(3)
                                    ->schema([
                                        Select::make('field')
                                            ->label(__('Field'))
                                            ->options(self::ruleFieldOptions())
                                            ->required()
                                            ->searchable()
                                            ->live(),
                                        Select::make('op')
                                            ->label(__('Operator'))
                                            ->options(fn (Get $get): array => self::operatorsForField($get('field')))
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(fn (?string $state, Set $set) => $set('value', in_array($state, ['in', 'not_in'], true) ? [] : null)),
                                        TextInput::make('value')
                                            ->label(__('Value'))
                                            ->required()
                                            ->visible(fn (Get $get): bool => ! in_array($get('op'), ['in', 'not_in'], true))
                                            ->placeholder(fn (Get $get): string => self::valuePlaceholder($get('field'))),
                                        TagsInput::make('value')
                                            ->label(__('Values'))
                                            ->required()
                                            ->splitKeys([',', 'Tab'])
                                            ->visible(fn (Get $get): bool => in_array($get('op'), ['in', 'not_in'], true))
                                            ->helperText(__('Press Enter, comma, or Tab after each value.')),
                                    ]),
                                Select::make('stream_profile_id')
                                    ->label(__('Use this profile'))
                                    ->options(fn (?StreamProfile $record) => self::transcodingProfileOptions($record))
                                    ->required()
                                    ->searchable()
                                    ->preload(),
                            ])
                            ->columnSpanFull(),

                        Select::make('else_stream_profile_id')
                            ->label(__('Otherwise (fallback)'))
                            ->options(fn (?StreamProfile $record) => self::transcodingProfileOptions($record))
                            ->required(fn (Get $get): bool => $get('backend') === 'adaptive')
                            ->searchable()
                            ->preload()
                            ->helperText(__('Used when no rule matches and when probe data is missing for a channel.')),
                    ]),
            ]);
    }

    /**
     * Grouped list of probe fields a rule condition can match on.
     */
    public static function ruleFieldOptions(): array
    {
        return [
            __('Video') => [
                'video.codec_name' => __('Codec'),
                'video.height' => __('Height (px)'),
                'video.width' => __('Width (px)'),
                'video.bit_rate' => __('Bitrate (bps)'),
                'video.frame_rate' => __('Frame rate (fps)'),
                'video.profile' => __('Profile'),
                'video.display_aspect_ratio' => __('Aspect ratio'),
            ],
            __('Audio') => [
                'audio.codec_name' => __('Codec'),
                'audio.channels' => __('Channels'),
                'audio.sample_rate' => __('Sample rate (Hz)'),
            ],
            __('Format') => [
                'format.format_name' => __('Format name'),
            ],
        ];
    }

    /**
     * Operators that make sense for a given probe field. Numeric fields
     * support comparison; string fields support equality and list membership.
     */
    public static function operatorsForField(?string $field): array
    {
        $numericFields = [
            'video.height', 'video.width', 'video.bit_rate', 'video.frame_rate',
            'audio.channels', 'audio.sample_rate',
        ];

        if (in_array($field, $numericFields, true)) {
            return [
                '=' => '=',
                '!=' => '≠',
                '>' => '>',
                '>=' => '≥',
                '<' => '<',
                '<=' => '≤',
            ];
        }

        return [
            '=' => '=',
            '!=' => '≠',
            'in' => __('is one of'),
            'not_in' => __('is not one of'),
        ];
    }

    public static function valuePlaceholder(?string $field): string
    {
        return match ($field) {
            'video.codec_name' => 'hevc',
            'video.height' => '1080',
            'video.width' => '1920',
            'video.bit_rate' => '5000000',
            'video.frame_rate' => '60',
            'video.profile' => 'High',
            'video.display_aspect_ratio' => '16:9',
            'audio.codec_name' => 'aac',
            'audio.channels' => '2',
            'audio.sample_rate' => '48000',
            'format.format_name' => 'hls',
            default => '',
        };
    }

    /**
     * Profile options for use as an adaptive rule target — excludes other
     * adaptive profiles (no chaining) and excludes the current record
     * (no self-reference).
     */
    public static function transcodingProfileOptions(?StreamProfile $record): array
    {
        return StreamProfile::query()
            ->where('user_id', auth()->id())
            ->where('backend', '!=', 'adaptive')
            ->when($record?->exists, fn ($q) => $q->where('id', '!=', $record->id))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Compact label for the collapsed rule item header.
     */
    public static function summarizeRule(array $state): ?string
    {
        $conditions = $state['conditions'] ?? [];
        if (empty($conditions)) {
            return null;
        }

        $parts = [];
        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? '';
            $op = $condition['op'] ?? '';
            $value = $condition['value'] ?? '';
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $parts[] = trim("{$field} {$op} {$value}");
        }

        return implode(' AND ', $parts);
    }

    public static function defaultArgsForBackend(?string $backend): string
    {
        return match ($backend) {
            'streamlink' => 'best --hls-live-edge 3',
            'ytdlp' => 'bestvideo+bestaudio/best --no-playlist',
            default => '-i {input_url} -c:v libx264 -preset faster -crf {crf|23} -maxrate {maxrate|2500k} -bufsize {bufsize|5000k} -c:a aac -b:a {audio_bitrate|192k} -f mpegts {output_args|pipe:1}',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('backend')
                    ->label(__('Backend'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'streamlink' => 'Streamlink',
                        'ytdlp' => 'yt-dlp',
                        'adaptive' => 'Adaptive',
                        default => 'FFmpeg',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'streamlink' => 'info',
                        'ytdlp' => 'warning',
                        'adaptive' => 'success',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): ?string => $state === 'adaptive'
                        ? 'heroicon-o-arrows-right-left'
                        : null)
                    ->sortable(),
                TextColumn::make('format')
                    ->label(__('Format'))
                    ->badge()
                    ->sortable()
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\DeleteAction::make()
                    ->before(function (StreamProfile $record, Actions\DeleteAction $action): void {
                        $referencing = $record->getReferencingAdaptiveProfiles();
                        if ($referencing->isEmpty()) {
                            return;
                        }

                        Notification::make()
                            ->danger()
                            ->title(__('Profile in use'))
                            ->body(__('This profile is referenced by the following adaptive profiles: ').$referencing->pluck('name')->join(', ').'. '.__('Remove the references before deleting.'))
                            ->persistent()
                            ->send();

                        $action->halt();
                    })
                    ->button()->hiddenLabel()->size('sm'),
                Actions\EditAction::make()
                    ->slideOver()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->before(function ($records, Actions\DeleteBulkAction $action): void {
                            $blocked = $records->filter(
                                fn (StreamProfile $record) => $record->getReferencingAdaptiveProfiles()->isNotEmpty()
                            );

                            if ($blocked->isEmpty()) {
                                return;
                            }

                            Notification::make()
                                ->danger()
                                ->title(__('Some profiles could not be deleted'))
                                ->body(__('The following profiles are referenced by adaptive profiles and cannot be deleted: ').$blocked->pluck('name')->join(', ').'. '.__('Remove the references before deleting.'))
                                ->persistent()
                                ->send();

                            $action->halt();
                        }),
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
            'index' => Pages\ListStreamProfiles::route('/'),
            // 'create' => Pages\CreateStreamProfile::route('/create'),
            // 'edit' => Pages\EditStreamProfile::route('/{record}/edit'),
        ];
    }
}
