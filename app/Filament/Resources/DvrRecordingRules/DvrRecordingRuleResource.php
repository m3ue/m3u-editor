<?php

namespace App\Filament\Resources\DvrRecordingRules;

use App\Enums\DvrRuleType;
use App\Enums\DvrSeriesMode;
use App\Models\Channel;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Settings\GeneralSettings;
use App\Traits\HasDvrMatchedAirings;
use App\Traits\HasUserFiltering;
use BackedEnum;
use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class DvrRecordingRuleResource extends Resource
{
    use HasDvrMatchedAirings;
    use HasUserFiltering;

    protected static ?string $model = DvrRecordingRule::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseDvr();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('DVR');
    }

    public static function getNavigationLabel(): string
    {
        return __('Recording Rules');
    }

    public static function getModelLabel(): string
    {
        return __('Recording Rule');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Recording Rules');
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('enabled')
                    ->label(__('Enabled'))
                    ->default(true)
                    ->columnSpanFull()
                    ->required(),

                Select::make('dvr_setting_id')
                    ->label(__('DVR Setting (Playlist)'))
                    ->options(fn () => DvrSetting::with(['playlist', 'customPlaylist', 'mergedPlaylist'])
                        ->where('user_id', Auth::id())
                        ->where('enabled', true)
                        ->get()
                        ->mapWithKeys(fn (DvrSetting $s) => [$s->id => $s->owner()?->name ?? "DVR #{$s->id}"]))
                    ->required()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('channel_id', null)),

                Select::make('type')
                    ->label(__('Rule Type'))
                    ->options(DvrRuleType::class)
                    ->default(DvrRuleType::Once->value)
                    ->required()
                    ->live(),

                DateTimePicker::make('manual_start')
                    ->label(__('Manual Start'))
                    ->native(false)
                    ->seconds(false)
                    ->timezone(app(GeneralSettings::class)->app_timezone ?: config('app.timezone'))
                    ->prefixIcon('heroicon-o-calendar')
                    ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Manual))
                    ->requiredIf('type', DvrRuleType::Manual->value),

                DateTimePicker::make('manual_end')
                    ->label(__('Manual End'))
                    ->native(false)
                    ->seconds(false)
                    ->timezone(app(GeneralSettings::class)->app_timezone ?: config('app.timezone'))
                    ->prefixIcon('heroicon-o-calendar')
                    ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Manual))
                    ->requiredIf('type', DvrRuleType::Manual->value)
                    ->after('manual_start'),

                Select::make('channel_id')
                    ->label(__('Channel'))
                    ->searchable()
                    // Playlists can have hundreds of thousands of channels, so this
                    // must never load/return the full channel list - search matches
                    // are queried lazily as the user types, selecting only the
                    // columns needed (Channel rows carry heavy JSON columns like
                    // movie_data/sync_settings/stream_stats that must not be
                    // hydrated just to build a dropdown label).
                    ->getSearchResultsUsing(function (Get $get, string $search): array {
                        $dvrSetting = DvrSetting::find($get('dvr_setting_id'));

                        if (! $dvrSetting) {
                            return [];
                        }

                        // Deduplicate by the label the option will actually show
                        // (title, or name when title is blank) - the IPTV provider
                        // may have multiple streams/quality variants for the same
                        // channel, but channels without a title must not collapse
                        // into a single "untitled" option.
                        $results = Channel::whereIn('id', $dvrSetting->ownerChannelsSubquery())
                            ->where(fn ($query) => $query->where('title', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%"))
                            ->orderBy('title')
                            ->limit(50)
                            ->get(['id', 'title', 'name'])
                            ->unique(fn (Channel $channel): string => $channel->title ?: $channel->name)
                            ->mapWithKeys(fn (Channel $channel): array => [
                                $channel->id => $channel->title ?: $channel->name,
                            ])
                            ->all();

                        if ($search === '' || str_contains(mb_strtolower(__('From Original Source')), mb_strtolower($search))) {
                            $results = [0 => __('From Original Source')] + $results;
                        }

                        return $results;
                    })
                    ->getOptionLabelUsing(fn (mixed $value): ?string => ((int) $value === 0)
                        ? __('From Original Source')
                        : (Channel::find($value)?->title ?: Channel::find($value)?->name))
                    ->disabled(fn (Get $get): bool => ! $get('dvr_setting_id'))
                    ->nullable()
                    ->live(onBlur: true)
                    ->helperText(fn (Get $get): ?string => self::isRuleType($get('type'), DvrRuleType::Series)
                        ? __('Series rules only match against channels with EPG data mapped. If a rule never records, confirm this channel has an EPG source assigned.')
                        : null),

                TextInput::make('series_title')
                    ->label(__('Series Title'))
                    ->placeholder(__('e.g. Breaking Bad'))
                    ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Series))
                    ->live(onBlur: true)
                    ->requiredIf('type', DvrRuleType::Series->value),

                Select::make('series_mode')
                    ->label(__('Record Episodes'))
                    ->options(DvrSeriesMode::class)
                    ->default(DvrSeriesMode::UniqueSe->value)
                    ->helperText(__('Set per-playlist defaults under Playlists → Edit → DVR Settings.'))
                    ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Series))
                    ->live(onBlur: true),

                TextInput::make('sports_dedup_days')
                    ->label(__('Sports Dedup Window (Days)'))
                    ->helperText(__('For sports airings without season/episode data: a same-title airing within this many days of a recent game is treated as a replay (skipped); beyond it, a new event is recorded. Blank uses the playlist default (2 days); 0 records every same-title airing.'))
                    ->numeric()
                    ->minValue(0)
                    ->placeholder(__('Playlist default'))
                    ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Series))
                    ->live(onBlur: true),

                Select::make('enable_comskip')
                    ->label(__('Commercial Detection (Comskip)'))
                    ->options([
                        1 => __('Enable'),
                        0 => __('Disable'),
                    ])
                    ->placeholder(__('Inherit from DVR Setting'))
                    ->nullable()
                    ->hintIcon('heroicon-m-question-mark-circle')
                    ->hintIconTooltip(__('When not set, the DVR Setting default is used. Comskip detects and marks commercials in recordings. The Emby.ComSkiper plugin for Emby is available at https://github.com/BillOatmanWork/Emby.ComSkipper')),

                TextInput::make('start_early_seconds')
                    ->label(__('Start Early (seconds)'))
                    ->numeric()
                    ->minValue(0)
                    ->placeholder(__('Leave blank to use playlist default')),

                TextInput::make('end_late_seconds')
                    ->label(__('End Late (seconds)'))
                    ->numeric()
                    ->minValue(0)
                    ->placeholder(__('Leave blank to use playlist default')),

                TextInput::make('keep_last')
                    ->label(__('Keep Last N Recordings'))
                    ->numeric()
                    ->minValue(1)
                    ->placeholder(__('Leave blank to keep all')),

                TextInput::make('priority')
                    ->label(__('Priority'))
                    ->numeric()
                    ->default(50)
                    ->required(),

                View::make('filament.forms.dvr-matched-airings')
                    ->viewData(fn (?DvrRecordingRule $record, Get $get, $livewire): array => static::buildMatchedAiringsPreviewProps($record, $get, $livewire))
                    ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Series))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Build the (cheap - no DB queries) props for the deferred matched-airings
     * preview. Resolving the actual airings can scan every EPG-mapped channel on
     * a playlist (hundreds of thousands of channels), so that work must never run
     * synchronously while the create/edit modal is opening - see
     * App\Filament\Concerns\HasDvrMatchedAiringsPreviewCache, which the hosting
     * Livewire component runs via wire:init in its own follow-up request.
     *
     * The 'cacheKey' identifies the current inputs (series_title, channel,
     * record-episodes mode, ...): whenever it changes, the blade view's
     * wire:key forces the preview element to be replaced, re-triggering
     * wire:init - this is what makes onBlur edits refresh the preview for
     * both new and edited rules. 'airings' is only populated once the
     * Livewire component's cached result matches the CURRENT cacheKey -
     * otherwise the view renders a loading placeholder.
     */
    protected static function buildMatchedAiringsPreviewProps(?DvrRecordingRule $record, Get $get, $livewire): array
    {
        $type = DvrRuleType::tryFrom($get('type')?->value ?? $get('type'));

        // On the initial mount the form state may not be filled yet, so fall back
        // to the record's values - the preview must render for existing rules
        // before any onBlur edit.
        $seriesTitle = trim((string) ($get('series_title') ?? $record?->series_title ?? ''));
        $dvrSettingId = $get('dvr_setting_id');

        $ruleAttributes = [
            'series_title' => $seriesTitle,
            'series_mode' => is_string($get('series_mode')) ? $get('series_mode') : $get('series_mode')?->value,
            'dvr_setting_id' => $dvrSettingId,
            'epg_channel_id' => $get('epg_channel_id') ?? $record?->epg_channel_id,
            'channel_id' => $get('channel_id') ?? $record?->channel_id,
            'source_channel_id' => $get('source_channel_id') ?? $record?->source_channel_id,
            'sports_dedup_days' => $get('sports_dedup_days') ?? $record?->sports_dedup_days,
        ];

        if ($type !== DvrRuleType::Series || $seriesTitle === '' || ! $dvrSettingId) {
            $ruleAttributes = [];
        }

        $cacheKey = md5(json_encode([$record?->id, $ruleAttributes]));

        return [
            'ruleId' => $record?->id,
            'ruleAttributes' => $ruleAttributes,
            'cacheKey' => $cacheKey,
            'airings' => $livewire->dvrAiringsPreviewKey === $cacheKey ? $livewire->dvrAiringsPreview : null,
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->sortable(),

                ToggleColumn::make('enabled')
                    ->label(__('Enabled'))
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('series_title')
                    ->label(__('Title / Pattern'))
                    ->state(fn (DvrRecordingRule $record): ?string => $record->series_title
                        ?? ($record->type === DvrRuleType::Once ? $record->programme?->title : null))
                    ->description(fn (DvrRecordingRule $record): string => match ($record->type) {
                        DvrRuleType::Once => __('One-time recording'),
                        DvrRuleType::Manual => $record->manual_start?->format('d M Y H:i') ?? '—',
                        default => '',
                    })
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('channel.title')
                    ->label(__('Channel'))
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('dvrSetting.playlist.name')
                    ->label(__('Playlist'))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('series_mode')
                    ->label(__('Mode'))
                    ->badge()
                    ->formatStateUsing(fn (DvrSeriesMode $state): string => $state->getLabel())
                    ->color(fn (DvrSeriesMode $state): string => $state->getColor())
                    ->toggleable()
                    ->visible(fn (?DvrRecordingRule $record): bool => $record && $record->type === DvrRuleType::Series),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(DvrRuleType::class),
            ])
            ->recordActions([
                Actions\DeleteAction::make()->button()
                    ->hiddenLabel()->size('sm'),
                Actions\EditAction::make()->button()
                    ->hiddenLabel()->size('sm')
                    ->slideOver()
                    ->mutateRecordDataUsing(function (array $data, DvrRecordingRule $record): array {
                        if ($record->channel_id === null && $record->source_channel_id !== null) {
                            $data['channel_id'] = 0;
                        }

                        return $data;
                    })
                    ->mutateDataUsing(function (array $data, DvrRecordingRule $record): array {
                        $channelId = $data['channel_id'] ?? null;

                        if ($channelId !== null && (int) $channelId === 0) {
                            $data['channel_id'] = null;
                            $data['source_channel_id'] = $record->source_channel_id;
                        } else {
                            $data['source_channel_id'] = null;
                        }

                        return $data;
                    }),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Compare a form field value against a rule type, handling both enum instances and backing strings.
     *
     * Filament may return a DvrRuleType enum instance (when editing an existing record)
     * or the raw string backing value (when creating or after a live() Select change).
     */
    private static function isRuleType(mixed $value, DvrRuleType $type): bool
    {
        return $value === $type || $value === $type->value;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDvrRecordingRules::route('/'),
            // 'create' => Pages\CreateDvrRecordingRule::route('/create'),
            // 'edit' => Pages\EditDvrRecordingRule::route('/{record}/edit'),
        ];
    }
}
