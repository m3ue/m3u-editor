<?php

namespace App\Filament\Resources\StreamProfiles;

use App\Models\StreamProfile;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class StreamProfileResource extends Resource
{
    protected static ?string $model = StreamProfile::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Proxy';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Profile Name')
                    ->required()
                    ->columnSpanFull()
                    ->maxLength(255)
                    ->helperText('A descriptive name for this transcoding profile (e.g., "720p Standard", "1080p High Quality")'),

                Textarea::make('description')
                    ->label('Description')
                    ->columnSpanFull()
                    ->rows(2)
                    ->maxLength(255)
                    ->helperText('Optional description of what this profile does'),

                Textarea::make('args')
                    ->label('FFmpeg Template')
                    ->required()
                    ->columnSpanFull()
                    ->rows(4)
                    ->hintAction(
                        Action::make('view_profile_docs')
                            ->label('View Docs')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->iconPosition('after')
                            ->size('sm')
                            ->url('https://github.com/sparkison/m3u-proxy/blob/master/docs/PROFILE_VARIABLES.md')
                            ->openUrlInNewTab(true)
                    )
                    ->default('-i {input_url} -c:v libx264 -preset faster -crf {crf|23} -maxrate {maxrate|2500k} -bufsize {bufsize|5000k} -c:a aac -b:a {audio_bitrate|192k} -f mpegts {output_args|pipe:1}')
                    ->placeholder('-i {input_url} -c:v libx264 -preset faster -crf {crf|23} -maxrate {maxrate|2500k} -bufsize {bufsize|5000k} -c:a aac -b:a {audio_bitrate|192k} -f mpegts {output_args|pipe:1}')
                    ->helperText('FFmpeg arguments for transcoding. Use placeholders like {crf|23} for configurable parameters with defaults. Hardware acceleration will be applied automatically by the proxy server.'),
                Select::make('format')
                    ->label('Stream Format')
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
                    ->helperText('The container format for the output stream.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('description')
                    ->label('Description')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('format')
                    ->label('Format')
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
