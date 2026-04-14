<?php

namespace App\Filament\Resources\StreamProfiles;

use App\Filament\Concerns\HasCopilotSupport;
use App\Models\StreamProfile;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
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
                    ])
                    ->searchable()
                    ->default('ffmpeg')
                    ->required()
                    ->live()
                    ->columnSpanFull()
                    ->helperText(__('FFmpeg re-encodes the stream. Streamlink and yt-dlp extract and deliver streams directly from supported platforms (Twitch, YouTube, etc.) without re-encoding.')),

                Textarea::make('args')
                    ->label(fn (Get $get): string => match ($get('backend')) {
                        'streamlink' => __('Quality & Options'),
                        'ytdlp' => __('Format Selector & Options'),
                        default => __('FFmpeg Template'),
                    })
                    ->required()
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
                    ->default(fn (Get $get): string => match ($get('backend')) {
                        'streamlink' => 'best',
                        'ytdlp' => 'bestvideo+bestaudio/best',
                        default => '-i {input_url} -c:v libx264 -preset faster -crf {crf|23} -maxrate {maxrate|2500k} -bufsize {bufsize|5000k} -c:a aac -b:a {audio_bitrate|192k} -f mpegts {output_args|pipe:1}',
                    })
                    ->placeholder(fn (Get $get): string => match ($get('backend')) {
                        'streamlink' => 'best',
                        'ytdlp' => 'bestvideo+bestaudio/best',
                        default => '-i {input_url} -c:v libx264 -preset faster -crf {crf|23} -maxrate {maxrate|2500k} -bufsize {bufsize|5000k} -c:a aac -b:a {audio_bitrate|192k} -f mpegts {output_args|pipe:1}',
                    })
                    ->helperText(fn (Get $get): string => match ($get('backend')) {
                        'streamlink' => __('Quality selector (best, worst, 720p, etc.) followed by optional Streamlink flags. Example: best --hls-live-edge 3'),
                        'ytdlp' => __('yt-dlp format selector followed by optional flags. Example: bestvideo+bestaudio/best --no-playlist'),
                        default => __('FFmpeg arguments for transcoding. Use placeholders like {crf|23} for configurable parameters with defaults. Hardware acceleration will be applied automatically by the proxy server.'),
                    }),

                Textarea::make('cookies')
                    ->label(__('Cookies (Netscape format)'))
                    ->placeholder("# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t0\tCOOKIE_NAME\tCOOKIE_VALUE")
                    ->helperText(__('Paste cookies.txt content for authenticated streams (e.g. YouTube members-only, age-gated). Get cookies using a browser extension like "Get cookies.txt LOCALLY".'))
                    ->rows(5)
                    ->columnSpanFull()
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
                    ->required()
                    ->helperText(fn (Get $get): string => match ($get('backend')) {
                        'streamlink' => __('The container format Streamlink will output. This sets the URL extension (e.g. .ts, .mp4) so players know how to handle the stream. Must match the format Streamlink actually produces for the selected quality.'),
                        'ytdlp' => __('The container format yt-dlp will output. This sets the URL extension (e.g. .ts, .mp4) so players know how to handle the stream. Must match the format produced by your yt-dlp format selector.'),
                        default => __('The container format FFmpeg will produce. Must match the -f muxer argument in your FFmpeg template above.'),
                    }),
            ]);
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
                        default => 'FFmpeg',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'streamlink' => 'info',
                        'ytdlp' => 'warning',
                        default => 'gray',
                    })
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
            'index' => Pages\ListStreamProfiles::route('/'),
            // 'create' => Pages\CreateStreamProfile::route('/create'),
            // 'edit' => Pages\EditStreamProfile::route('/{record}/edit'),
        ];
    }
}
