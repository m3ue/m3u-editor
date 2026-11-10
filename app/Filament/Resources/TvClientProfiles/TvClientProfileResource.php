<?php

namespace App\Filament\Resources\TvClientProfiles;

use App\Filament\Clusters\Devices\DevicesCluster;
use App\Filament\Resources\TvClientProfiles\Pages\ListTvClientProfiles;
use App\Models\TvClientProfile;
use App\Settings\GeneralSettings;
use App\Support\TvClientSettings;
use App\Traits\HasUserFiltering;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

/**
 * M3U TV app settings managed from the editor. Each Playlist Auth can use a
 * profile; the default profile covers every other login. Lives in the Devices
 * cluster and, like Device Pairing, only while enhanced Xtream API output is
 * on, since M3U TV can't connect without it.
 */
class TvClientProfileResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = TvClientProfile::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $cluster = DevicesCluster::class;

    // Slug is prefixed by the Devices cluster - the admin URL is /devices/profiles.
    protected static ?string $slug = 'profiles';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin() && static::isEnabled();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::isEnabled();
    }

    /**
     * Profiles only reach the app through the enhanced Xtream API output.
     */
    public static function isEnabled(): bool
    {
        try {
            return (bool) (app(GeneralSettings::class)->app_output_enabled ?? true);
        } catch (\Exception $e) {
            return true;
        }
    }

    public static function getNavigationLabel(): string
    {
        return __('Settings Profiles');
    }

    public static function getModelLabel(): string
    {
        return __('Settings Profile');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Settings Profiles');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make(__('Requires M3U TV :version or later', ['version' => TvClientSettings::MIN_APP_VERSION]))
                    ->description(__('Older versions ignore profiles. Settings left as "Not managed" keep the app\'s own behavior.'))
                    ->info()
                    ->columnSpanFull(),
                TextInput::make('name')
                    ->label(__('Profile Name'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->label(__('Description'))
                    ->rows(2)
                    ->maxLength(255)
                    ->columnSpanFull(),
                Toggle::make('is_default')
                    ->label(__('Default profile'))
                    ->helperText(__('Applies to your own login, alias logins and Playlist Auths without a profile. Only one profile can be the default.'))
                    ->default(false)
                    ->columnSpanFull(),
                ...static::settingsSections(),
            ]);
    }

    /**
     * One collapsible section per settings group, one mode + value row per
     * setting, built from TvClientSettings so the form and the API never drift.
     *
     * @return array<int, Section>
     */
    private static function settingsSections(): array
    {
        $definitions = collect(TvClientSettings::definitions());
        $sections = [];

        foreach (TvClientSettings::groups() as $group => $groupLabel) {
            $rows = $definitions
                ->filter(fn (array $definition): bool => $definition['group'] === $group)
                ->map(fn (array $definition, string $key): Grid => static::settingRow($key, $definition))
                ->values()
                ->all();

            $sections[] = Section::make($groupLabel)
                ->compact()
                ->collapsible()
                ->schema($rows)
                ->columnSpanFull();
        }

        return $sections;
    }

    /**
     * @param  array{type: string, label: string, helper?: string, options?: array<string|int, string>, app_default: string|int|bool, platforms?: string}  $definition
     */
    private static function settingRow(string $key, array $definition): Grid
    {
        $modePath = "settings.{$key}.mode";
        $valuePath = "settings.{$key}.value";
        $isManaged = fn (Get $get): bool => filled($get($modePath));

        $helper = collect([
            $definition['helper'] ?? null,
            $definition['platforms'] ?? null,
            __('App default: :value', ['value' => static::describeValue($definition, $definition['app_default'])]),
        ])->filter()->implode(' ');

        $valueField = $definition['type'] === TvClientSettings::TYPE_BOOL
            ? Toggle::make($valuePath)
                ->label(__('Value'))
                ->inline(false)
            : Select::make($valuePath)
                ->label(__('Value'))
                ->options($definition['options'] ?? [])
                ->native(false)
                ->required(fn (Get $get): bool => $isManaged($get));

        return Grid::make(2)
            ->schema([
                Select::make($modePath)
                    ->label($definition['label'])
                    ->helperText($helper)
                    ->placeholder(__('Not managed'))
                    ->options([
                        TvClientSettings::MODE_DEFAULT => __('Default (user can change)'),
                        TvClientSettings::MODE_ENFORCED => __('Enforced (locked)'),
                    ])
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state) use ($valuePath, $definition): void {
                        if (filled($state) && $get($valuePath) === null) {
                            $set($valuePath, $definition['app_default']);
                        }
                    })
                    ->columnSpan(1),
                $valueField
                    ->visible($isManaged)
                    ->columnSpan(1),
            ]);
    }

    /**
     * @param  array{type: string, options?: array<string|int, string>}  $definition
     */
    private static function describeValue(array $definition, string|int|bool $value): string
    {
        if ($definition['type'] === TvClientSettings::TYPE_BOOL) {
            return $value ? __('On') : __('Off');
        }

        return (string) ($definition['options'][$value] ?? $value);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->toggleable(),
                IconColumn::make('is_default')
                    ->label(__('Default'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('managed_settings_count')
                    ->label(__('Managed Settings'))
                    ->state(fn (TvClientProfile $record): int => count($record->managedSettings()))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('playlist_auths_count')
                    ->label(__('Playlist Auths'))
                    ->counts('playlistAuths')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->button()->hiddenLabel()->size('sm'),
                EditAction::make()
                    ->slideOver()
                    ->button()->hiddenLabel()->size('sm'),
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
            'index' => ListTvClientProfiles::route('/'),
        ];
    }
}
