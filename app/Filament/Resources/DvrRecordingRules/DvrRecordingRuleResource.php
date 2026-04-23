<?php

namespace App\Filament\Resources\DvrRecordingRules;

use App\Enums\DvrRuleType;
use App\Models\Channel;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Traits\HasUserFiltering;
use BackedEnum;
use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class DvrRecordingRuleResource extends Resource
{
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
                    ->options(fn () => DvrSetting::with('playlist')
                        ->where('user_id', Auth::id())
                        ->get()
                        ->mapWithKeys(fn (DvrSetting $s) => [$s->id => $s->playlist?->name ?? "DVR #{$s->id}"]))
                    ->required()
                    ->searchable(),

                Select::make('type')
                    ->label(__('Rule Type'))
                    ->options(DvrRuleType::class)
                    ->default(DvrRuleType::Once->value)
                    ->required()
                    ->live(),

                DateTimePicker::make('manual_start')
                    ->label(__('Manual Start'))
                    ->native(false)
                    ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Manual))
                    ->requiredIf('type', DvrRuleType::Manual->value),

                DateTimePicker::make('manual_end')
                    ->label(__('Manual End'))
                    ->native(false)
                    ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Manual))
                    ->requiredIf('type', DvrRuleType::Manual->value)
                    ->after('manual_start'),

                Select::make('channel_id')
                    ->label(__('Channel'))
                    ->options(fn () => Channel::query()
                        ->where('user_id', Auth::id())
                        ->orderBy('title')
                        ->pluck('title', 'id'))
                    ->searchable()
                    ->nullable(),

                TextInput::make('series_title')
                    ->label(__('Series Title'))
                    ->placeholder(__('e.g. Breaking Bad'))
                    ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Series))
                    ->requiredIf('type', DvrRuleType::Series->value),

                Toggle::make('new_only')
                    ->label(__('New Episodes Only'))
                    ->visible(fn (Get $get): bool => self::isRuleType($get('type'), DvrRuleType::Series))
                    ->default(false),

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
            ]);
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

                IconColumn::make('new_only')
                    ->label(__('New Only'))
                    ->boolean()
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
                Actions\DeleteAction::make()->button()
                    ->hiddenLabel()->size('sm'),
                Actions\EditAction::make()->button()
                    ->hiddenLabel()->size('sm')
                    ->slideOver(),
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
