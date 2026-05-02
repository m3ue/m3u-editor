<?php

namespace App\Filament\GuestPanel\Resources\DvrRules;

use App\Enums\DvrRuleType;
use App\Enums\DvrSeriesMode;
use App\Filament\GuestPanel\Pages\Concerns\HasGuestDvr;
use App\Models\Channel;
use App\Models\DvrRecordingRule;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GuestDvrRuleResource extends Resource
{
    use HasGuestDvr;

    protected static ?string $model = DvrRecordingRule::class;

    protected static ?string $slug = 'dvr-rules';

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

    protected static ?int $navigationSort = 5;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-s-calendar-days';

    public static function canAccess(): bool
    {
        return static::guestCanAccessDvr();
    }

    public static function canCreate(): bool
    {
        return static::guestCanAccessDvr();
    }

    public static function canEdit(Model $record): bool
    {
        $auth = static::getCurrentPlaylistAuth();

        return $auth && $record->playlist_auth_id === $auth->id;
    }

    public static function canDelete(Model $record): bool
    {
        $auth = static::getCurrentPlaylistAuth();

        return $auth && $record->playlist_auth_id === $auth->id;
    }

    public static function getUrl(
        ?string $name = null,
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?Model $tenant = null,
        bool $shouldGuessMissingParameters = false,
        ?string $configuration = null
    ): string {
        $parameters['uuid'] = static::getCurrentUuid();
        $routeName = static::getRouteBaseName($panel).'.'.($name ?? 'index');

        return route($routeName, $parameters, $isAbsolute);
    }

    public static function getEloquentQuery(): Builder
    {
        $dvrSetting = static::getDvrSetting();
        if (! $dvrSetting) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->with(['channel', 'playlistAuth'])
            ->where('dvr_setting_id', $dvrSetting->id)
            ->orderByDesc('created_at');
    }

    /**
     * Shared form component definitions used in both the resource form
     * and the inline create/edit actions.
     *
     * @return array<int, mixed>
     */
    private static function formComponents(): array
    {
        $dvrSetting = static::getDvrSetting();
        $playlistId = $dvrSetting?->playlist_id;

        return [
            Toggle::make('enabled')
                ->label(__('Enabled'))
                ->default(true)
                ->columnSpanFull()
                ->required(),

            Select::make('type')
                ->label(__('Rule Type'))
                ->options([
                    DvrRuleType::Manual->value => DvrRuleType::Manual->getLabel(),
                    DvrRuleType::Series->value => DvrRuleType::Series->getLabel(),
                ])
                ->default(DvrRuleType::Manual->value)
                ->required()
                ->live(),

            DateTimePicker::make('manual_start')
                ->label(__('Start Time'))
                ->native(false)
                ->seconds(false)
                ->timezone(app(GeneralSettings::class)->app_timezone ?: config('app.timezone'))
                ->prefixIcon('heroicon-o-calendar')
                ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Manual))
                ->requiredIf('type', DvrRuleType::Manual->value),

            DateTimePicker::make('manual_end')
                ->label(__('End Time'))
                ->native(false)
                ->seconds(false)
                ->timezone(app(GeneralSettings::class)->app_timezone ?: config('app.timezone'))
                ->prefixIcon('heroicon-o-calendar')
                ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Manual))
                ->requiredIf('type', DvrRuleType::Manual->value)
                ->after('manual_start'),

            Select::make('channel_id')
                ->label(__('Channel'))
                ->options(fn () => $playlistId
                    ? Channel::query()
                        ->where('playlist_id', $playlistId)
                        ->orderBy('title')
                        ->pluck('title', 'id')
                    : [])
                ->searchable()
                ->nullable(),

            TextInput::make('series_title')
                ->label(__('Series Title'))
                ->placeholder(__('e.g. Breaking Bad'))
                ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Series))
                ->requiredIf('type', DvrRuleType::Series->value),

            Select::make('series_mode')
                ->label(__('Record Episodes'))
                ->options(DvrSeriesMode::class)
                ->default(DvrSeriesMode::All->value)
                ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Series)),

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
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::formComponents());
    }

    public static function table(Table $table): Table
    {
        $currentAuth = static::getCurrentPlaylistAuth();

        return $table
            ->filtersTriggerAction(fn ($action) => $action->button()->label(__('Filter')))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->sortable(),

                ToggleColumn::make('enabled')
                    ->label(__('Enabled'))
                    ->disabled(fn (DvrRecordingRule $record): bool => ! ($currentAuth && $record->playlist_auth_id === $currentAuth->id))
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('series_title')
                    ->label(__('Title / Pattern'))
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

                TextColumn::make('series_mode')
                    ->label(__('Mode'))
                    ->badge()
                    ->formatStateUsing(fn (DvrSeriesMode $state): string => $state->getLabel())
                    ->color(fn (DvrSeriesMode $state): string => $state->getColor())
                    ->toggleable(),

                TextColumn::make('playlistAuth.username')
                    ->label(__('Created By'))
                    ->placeholder(__('Playlist Owner'))
                    ->toggleable(),

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
                EditAction::make()
                    ->before(function (DvrRecordingRule $record, Action $action) use ($currentAuth): void {
                        if (! $currentAuth || $record->playlist_auth_id !== $currentAuth->id) {
                            Notification::make()
                                ->danger()
                                ->title(__('Unauthorized'))
                                ->send();

                            $action->halt();
                        }
                    })
                    ->button()
                    ->hiddenLabel()
                    ->size('sm')
                    ->slideOver(),

                DeleteAction::make()
                    ->before(function (DvrRecordingRule $record, Action $action) use ($currentAuth): void {
                        if (! $currentAuth || $record->playlist_auth_id !== $currentAuth->id) {
                            Notification::make()
                                ->danger()
                                ->title(__('Unauthorized'))
                                ->send();

                            $action->halt();
                        }
                    })
                    ->button()
                    ->hiddenLabel()
                    ->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Action::make('create')
                    ->label(__('New Rule'))
                    ->icon('heroicon-o-plus')
                    ->slideOver()
                    ->visible(fn (): bool => static::canCreate())
                    ->schema(static::formComponents())
                    ->action(function (array $data): void {
                        $dvrSetting = static::getDvrSetting();
                        $auth = static::getCurrentPlaylistAuth();

                        if (! $dvrSetting || ! $auth) {
                            Notification::make()
                                ->danger()
                                ->title(__('Unauthorized'))
                                ->send();

                            return;
                        }

                        DvrRecordingRule::create([
                            ...$data,
                            'dvr_setting_id' => $dvrSetting->id,
                            'user_id' => $dvrSetting->user_id,
                            'playlist_auth_id' => $auth?->id,
                        ]);

                        Notification::make()
                            ->success()
                            ->title(__('Recording rule created'))
                            ->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGuestDvrRules::route('/'),
        ];
    }

    /**
     * Compare a form field value against a rule type, handling both enum instances and backing strings.
     */
    private static function isRuleType(mixed $value, DvrRuleType $type): bool
    {
        return $value === $type || $value === $type->value;
    }
}
