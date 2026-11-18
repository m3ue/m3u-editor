<?php

namespace App\Filament\Resources\AioStreamsMatchProfiles;

use App\Filament\Clusters\MediaServers\MediaServersCluster;
use App\Filament\Resources\AioStreamsMatchProfiles\Pages\CreateAioStreamsMatchProfile;
use App\Filament\Resources\AioStreamsMatchProfiles\Pages\EditAioStreamsMatchProfile;
use App\Filament\Resources\AioStreamsMatchProfiles\Pages\ListAioStreamsMatchProfiles;
use App\Models\AioStreamsMatchProfile;
use App\Models\MediaServerIntegration;
use App\Services\AioStreamsQualityParser;
use App\Traits\HasUserFiltering;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Locale;

/**
 * AIOStreams auto-selection ("match") profiles: rules for which of a title's
 * streams the auto-matcher picks and in what order. Assigned per AIOStreams
 * integration (movie + series defaults) and optionally per title.
 */
class AioStreamsMatchProfileResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = AioStreamsMatchProfile::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $cluster = MediaServersCluster::class;

    protected static ?string $slug = 'aiostreams-profiles';

    public static function getNavigationLabel(): string
    {
        return __('AIOStreams Profiles');
    }

    public static function getModelLabel(): string
    {
        return __('AIOStreams Profile');
    }

    public static function getPluralModelLabel(): string
    {
        return __('AIOStreams Profiles');
    }

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseIntegrations();
    }

    /**
     * Listed in the Media Servers cluster only once the user has an AIOStreams
     * integration (and AIOStreams is enabled), since profiles do nothing
     * before that - the same approach as Request Content.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return config('services.aiostreams.enabled', true)
            && MediaServerIntegration::query()
                ->where('user_id', auth()->id())
                ->where('type', 'aiostreams')
                ->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Profile'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->placeholder(__('e.g. 4K English, fall back to 1080p'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('description')
                            ->label(__('Description'))
                            ->maxLength(255),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make(__('Resolution'))
                    ->description(__('Preferred resolutions, best first. Drag to reorder. Leave empty to prefer the highest resolution available.'))
                    ->icon('heroicon-o-tv')
                    ->schema([
                        Repeater::make('rules.resolutions')
                            ->hiddenLabel()
                            ->simple(
                                Select::make('resolution')
                                    ->options(self::resolutionOptions())
                                    ->required(),
                            )
                            ->reorderable()
                            ->reorderableWithDragAndDrop()
                            ->distinct()
                            ->defaultItems(0)
                            ->addActionLabel(__('Add resolution')),
                        Toggle::make('rules.only_listed_resolutions')
                            ->label(__('Only allow these resolutions'))
                            ->helperText(__('Skip streams in any other (or an unknown) resolution, instead of just ranking them lower.')),
                    ])
                    ->columnSpan(1),

                Section::make(__('Audio language'))
                    ->description(__('Preferred audio languages, best first. Drag to reorder. Most releases do not tag their audio language and are in the original language of the title, so add "Original audio" to rank those too.'))
                    ->icon('heroicon-o-language')
                    ->schema([
                        Repeater::make('rules.languages')
                            ->hiddenLabel()
                            ->simple(
                                Select::make('language')
                                    ->options(self::languageOptions())
                                    ->searchable()
                                    ->required(),
                            )
                            ->reorderable()
                            ->reorderableWithDragAndDrop()
                            ->distinct()
                            ->defaultItems(0)
                            ->addActionLabel(__('Add language')),
                        Toggle::make('rules.require_language')
                            ->label(__('Only allow these languages'))
                            ->helperText(__('Skip streams whose audio language is not in the list, e.g. foreign-language dubs and voice-overs.')),
                    ])
                    ->columnSpan(1),

                Section::make(__('Quality'))
                    ->icon('heroicon-o-film')
                    ->schema([
                        CheckboxList::make('rules.excluded_sources')
                            ->label(__('Exclude sources'))
                            ->options(self::sourceOptions())
                            ->columns(3),
                        Grid::make(2)->schema([
                            Toggle::make('rules.avoid_hdr')
                                ->label(__('Exclude HDR')),
                            Toggle::make('rules.avoid_dv')
                                ->label(__('Exclude Dolby Vision')),
                            Toggle::make('rules.avoid_lossless_audio')
                                ->label(__('Exclude lossless audio (TrueHD, DTS)')),
                        ]),
                        CheckboxList::make('rules.avoid_codecs')
                            ->label(__('Exclude video codecs'))
                            ->options([
                                'av1' => 'AV1',
                                'hevc' => 'HEVC (H.265)',
                                'avc' => 'AVC (H.264)',
                            ])
                            ->columns(3),
                        Select::make('rules.max_audio_channels')
                            ->label(__('Maximum audio channels'))
                            ->options([
                                '2' => '2.0',
                                '5.1' => '5.1',
                                '7.1' => '7.1',
                            ])
                            ->placeholder(__('Any')),
                    ])
                    ->columnSpan(1),

                Section::make(__('Keywords and size'))
                    ->description(__('Keywords match the stream name, description and release filename, ignoring case.'))
                    ->icon('heroicon-o-funnel')
                    ->schema([
                        TagsInput::make('rules.excluded_keywords')
                            ->label(__('Exclude keywords'))
                            ->placeholder(__('e.g. CAM, Dubbed'))
                            ->helperText(__('Skip any stream that mentions one of these.')),
                        TagsInput::make('rules.preferred_keywords')
                            ->label(__('Preferred keywords'))
                            ->placeholder(__('e.g. REMUX, Atmos'))
                            ->helperText(__('Rank streams that mention more of these higher, after language and resolution.')),
                        Grid::make(2)->schema([
                            TextInput::make('rules.min_size_gb')
                                ->label(__('Minimum size (GB)'))
                                ->numeric()
                                ->minValue(0),
                            TextInput::make('rules.max_size_gb')
                                ->label(__('Maximum size (GB)'))
                                ->numeric()
                                ->minValue(0),
                        ]),
                        Toggle::make('rules.prefer_smaller')
                            ->label(__('Prefer smaller files'))
                            ->helperText(__('Break ties with the smallest file instead of the largest.')),
                    ])
                    ->columnSpan(1),

                Section::make(__('Selection'))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->schema([
                        Toggle::make('rules.prefer_cached')
                            ->label(__('Prefer cached streams'))
                            ->helperText(__('Rank streams your debrid service already has cached first.'))
                            ->default(true),
                        TextInput::make('rules.max_candidates')
                            ->label(__('Streams to keep'))
                            ->helperText(__('How many streams to store (the first plays, the rest are failovers). Leave empty to use the global setting.'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(20),
                        ToggleButtons::make('rules.when_no_match')
                            ->label(__('When no stream matches'))
                            ->options([
                                'best_available' => __('Use the best available stream'),
                                'leave_unresolved' => __('Leave unresolved'),
                            ])
                            ->helperText(__('"Leave unresolved" keeps the title unplayable until a matching release appears (it is retried hourly).'))
                            ->default('best_available')
                            ->inline()
                            ->grouped(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->description(fn (AioStreamsMatchProfile $record): ?string => $record->description)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('summary')
                    ->label(__('Rules'))
                    ->state(fn (AioStreamsMatchProfile $record): string => self::summarize($record->getRules()))
                    ->wrap(),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->button()
                    ->hiddenLabel()
                    ->size('sm'),
                DeleteAction::make()
                    ->button()
                    ->hiddenLabel()
                    ->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAioStreamsMatchProfiles::route('/'),
            'create' => CreateAioStreamsMatchProfile::route('/create'),
            'edit' => EditAioStreamsMatchProfile::route('/{record}/edit'),
        ];
    }

    /**
     * Profiles the current user can assign, for integration/title selects.
     *
     * @return array<int, string>
     */
    public static function profileOptions(): array
    {
        return AioStreamsMatchProfile::query()
            ->where('user_id', auth()->id())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function resolutionOptions(): array
    {
        return [
            '2160' => '4K (2160p)',
            '1440' => '1440p',
            '1080' => '1080p',
            '720' => '720p',
            '480' => 'SD (480p)',
        ];
    }

    /**
     * Language names come from intl in the current app locale, so they need
     * no lang/ entries.
     *
     * @return array<string, string>
     */
    public static function languageOptions(): array
    {
        return collect(AioStreamsQualityParser::languageCodes())
            ->mapWithKeys(fn (string $code) => [$code => match ($code) {
                'original' => __('Original audio (no language tag)'),
                'multi' => __('Multi-audio (MULTi / DUAL)'),
                default => mb_convert_case(Locale::getDisplayLanguage($code, app()->getLocale()), MB_CASE_TITLE),
            }])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function sourceOptions(): array
    {
        return [
            'remux' => 'Remux',
            'bluray' => 'BluRay',
            'web-dl' => 'WEB-DL',
            'webrip' => 'WEBRip',
            'hdtv' => 'HDTV',
            'dvd' => 'DVD',
            'screener' => __('Screener'),
            'telecine' => __('Telecine'),
            'telesync' => __('Telesync (TS)'),
            'cam' => 'CAM',
        ];
    }

    /**
     * Short one-line description of a profile's rules for the table.
     *
     * @param  array<string, mixed>  $rules
     */
    public static function summarize(array $rules): string
    {
        $resolutionOptions = self::resolutionOptions();
        $languageOptions = self::languageOptions();

        $resolutions = collect($rules['resolutions'] ?? [])->map(fn ($value) => $resolutionOptions[(string) $value] ?? $value);
        $languages = collect($rules['languages'] ?? [])->map(fn ($value) => $languageOptions[$value] ?? $value);

        $parts = array_filter([
            $resolutions->isNotEmpty() ? $resolutions->implode(' > ') : null,
            $languages->isNotEmpty() ? $languages->implode(' > ') : null,
            ! empty($rules['excluded_keywords']) ? __('Excludes: :keywords', ['keywords' => implode(', ', $rules['excluded_keywords'])]) : null,
        ]);

        return $parts === [] ? __('Default ranking') : implode(' · ', $parts);
    }
}
