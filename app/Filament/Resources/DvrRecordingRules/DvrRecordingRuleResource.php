<?php

namespace App\Filament\Resources\DvrRecordingRules;

use App\Enums\DvrMatchMode;
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
                    ->options(function (Get $get): array {
                        $dvrSetting = DvrSetting::find($get('dvr_setting_id'));

                        if (! $dvrSetting) {
                            return [];
                        }

                        // Deduplicate by title — the IPTV provider may have
                        // multiple streams/quality variants for the same channel.
                        // Channels without a title fall back to their name so
                        // the option label is never null.
                        return Channel::whereIn('id', $dvrSetting->ownerChannelsSubquery())
                            ->orderBy('title')
                            ->get()
                            ->unique('title')
                            ->mapWithKeys(fn (Channel $channel): array => [
                                $channel->id => $channel->title ?: $channel->name,
                            ])
                            ->prepend(__('From Original Source'), 0)
                            ->all();
                    })
                    ->disabled(fn (Get $get): bool => ! $get('dvr_setting_id'))
                    ->searchable()
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
                    ->viewData(fn (?DvrRecordingRule $record, Get $get): array => [
                        'airings' => static::resolveMatchedAiringsFromForm($record, $get),
                    ])
                    ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Series))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Build a temporary DvrRecordingRule from form values to preview
     * matched airings. Works for both new rules (no record yet) and
     * existing rules being edited — the preview always reflects the form's
     * CURRENT (possibly unsaved) values, so onBlur changes to the title,
     * channel or record-episodes mode re-render the airings immediately.
     */
    protected static function resolveMatchedAiringsFromForm(?DvrRecordingRule $record, Get $get): array
    {
        $type = DvrRuleType::tryFrom($get('type')?->value ?? $get('type'));
        if ($type !== DvrRuleType::Series) {
            return [];
        }

        // On the initial mount the form state may not be filled yet, so fall back
        // to the record's values — the preview must render for existing rules
        // before any onBlur edit.
        $seriesTitle = trim((string) ($get('series_title') ?? $record?->series_title ?? ''));
        if ($seriesTitle === '') {
            return [];
        }

        $dvrSettingId = $get('dvr_setting_id');
        if (! $dvrSettingId) {
            return [];
        }

        $formMatchMode = is_string($get('match_mode'))
            ? DvrMatchMode::tryFrom($get('match_mode'))
            : $get('match_mode');

        $tempRule = new DvrRecordingRule([
            // Existing records supply the base (fields outside the form —
            // tmdb_id, enable_comskip, keep_last, ...) so edited rules preview
            // with their full context.
            ...($record?->getAttributes() ?? []),
            'series_title' => $seriesTitle,
            'match_mode' => $formMatchMode ?? $record?->match_mode ?? DvrMatchMode::Contains,
            'series_mode' => is_string($get('series_mode'))
                ? (DvrSeriesMode::tryFrom($get('series_mode')) ?? $record?->series_mode)
                : ($get('series_mode') ?? $record?->series_mode ?? DvrSeriesMode::All),
            'dvr_setting_id' => $dvrSettingId,
            'epg_channel_id' => $get('epg_channel_id') ?? $record?->epg_channel_id,
            'channel_id' => $get('channel_id') ?? $record?->channel_id,
            'source_channel_id' => $get('source_channel_id') ?? $record?->source_channel_id,
            'sports_dedup_days' => $get('sports_dedup_days') ?? $record?->sports_dedup_days,
        ]);

        return static::resolveMatchedAirings($tempRule);
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
