<?php

namespace App\Filament\Resources\PostProcesses;

use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\PostProcesses\Pages\EditPostProcess;
use App\Filament\Resources\PostProcesses\Pages\ListPostProcesses;
use App\Filament\Resources\PostProcesses\RelationManagers\LogsRelationManager;
use App\Filament\Resources\PostProcesses\RelationManagers\ProcessesRelationManager;
use App\Models\PostProcess;
use App\Rules\CheckIfUrlOrLocalPath;
use App\Services\DateFormatService;
use App\Traits\HasUserFiltering;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class PostProcessResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
    use HasUserFiltering;

    protected static ?string $model = PostProcess::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $label = 'Post Process';

    protected static ?string $pluralLabel = 'Post Processing';

    public static function getNavigationGroup(): ?string
    {
        return __('Tools');
    }

    public static function getModelLabel(): string
    {
        return __('Post Process');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Post Processing');
    }

    protected static ?int $navigationSort = 4;

    /**
     * Check if the user can access this page.
     * Only users with the "tools" permission can access this page.
     */
    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseTools();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm($schema->getOperation()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->toggleable(),
                ToggleColumn::make('enabled')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('event')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('processes_count')
                    ->label(__('Items'))
                    ->counts('processes')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ProcessesRelationManager::class,
            LogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPostProcesses::route('/'),
            'edit' => EditPostProcess::route('/{record}/edit'),
        ];
    }

    public static function getForm($operation = 'create'): array
    {
        $schema = [
            Toggle::make('enabled')
                ->default(true)->helperText(__('Enable this post process')),
            Toggle::make('metadata.send_failed')
                ->label(__('Process failed'))
                ->default(false)->helperText(__('Process on failed syncs too (default is only successful syncs).')),
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->helperText(__('A descriptive name for this post process.')),
            Select::make('event')
                ->required()
                ->native(false)
                ->options([
                    'synced' => 'Synced',
                    'vod_stream_files_synced' => 'VOD Stream Files Synced',
                    'series_stream_files_synced' => 'Series Stream Files Synced',
                    'created' => 'Created',
                    // 'updated' => 'Updated', // Can lead to a lot of calls! Updates are called during the sync process.
                    'deleted' => 'Deleted',
                ])
                ->default('synced')
                ->helperText(__('The event that will trigger this post process. "VOD/Series Stream Files Synced" fires after the respective .strm file sync completes (requires .strm sync to be enabled on the playlist).')),
            ToggleButtons::make('metadata.local')
                ->label(__('Type'))
                ->grouped()
                ->required()
                ->columnSpanFull()
                ->options([
                    'url' => 'URL',
                    'path' => 'Local file',
                    'email' => 'Email',
                ])
                ->icons([
                    'url' => 'heroicon-s-link',
                    'path' => 'heroicon-s-document',
                    'email' => 'heroicon-s-envelope',
                ])
                ->live()
                ->default('url')
                ->helperText(__('Select whether to send a request to a URL, execute a local script, or send an email.')),
            TextInput::make('metadata.path')
                ->label(fn (Get $get) => ucfirst($get('metadata.local') ?? 'url'))
                ->columnSpan(2)
                ->prefixIcon(fn (Get $get) => match ($get('metadata.local')) {
                    'url' => 'heroicon-o-globe-alt',
                    'path' => 'heroicon-o-document',
                    'email' => 'heroicon-o-envelope',
                    default => 'heroicon-o-question-mark-circle',
                })
                ->placeholder(fn (Get $get) => match ($get('metadata.local')) {
                    'url' => route('webhook.test.get'),
                    'path' => '/var/www/html/custom_script',
                    'email' => 'user@example.com',
                    default => null,
                })
                ->helperText(fn (Get $get) => match ($get('metadata.local')) {
                    'url' => 'The URL to your webhook endpoint.',
                    'path' => 'The path to your local script.',
                    'email' => 'The email address to send notifications to.',
                    default => 'The URL or path to your webhook endpoint.',
                })
                ->required()
                ->rules(fn (Get $get) => $get('metadata.local') === 'email' ? [
                    'email',
                ] : [
                    new CheckIfUrlOrLocalPath(
                        urlOnly: $get('metadata.local') === 'url',
                        localOnly: $get('metadata.local') === 'path',
                    ),
                ])
                ->maxLength(255),
            Fieldset::make(__('Request Options'))
                ->schema([
                    ToggleButtons::make('metadata.post')
                        ->label(__('Request type'))
                        ->grouped()
                        ->required()
                        ->boolean()
                        ->options([
                            false => 'GET',
                            true => 'POST',
                        ])
                        ->icons([
                            false => 'heroicon-s-arrow-down-on-square',
                            true => 'heroicon-s-arrow-up-on-square',
                        ])
                        ->colors([
                            false => 'success',
                            true => 'primary',
                        ])
                        ->default(false)
                        ->live()
                        ->helperText(__('Send as GET or POST request.')),

                    Fieldset::make(__('Headers'))
                        ->schema([
                            Repeater::make('metadata.headers')
                                ->label(__('Custom Headers'))
                                ->schema([
                                    TextInput::make('header_name')
                                        ->label(__('Header name'))
                                        ->placeholder(__('X-Emby-Token'))
                                        ->helperText(__('Name of the HTTP header.'))
                                        ->datalist([
                                            'X-Emby-Token',
                                            'X-Api-Key',
                                            'Authorization',
                                            'X-Custom-Header',
                                        ])
                                        ->required(),
                                    TextInput::make('header_value')
                                        ->label(__('Header value'))
                                        ->placeholder(__('your-api-key-here'))
                                        ->helperText(__('Value for this header.'))
                                        ->required(),
                                ])
                                ->columns(2)
                                ->columnSpanFull()
                                ->collapsed()
                                ->addActionLabel('Add header'),
                        ]),

                    Fieldset::make(__('Body'))
                        ->schema([
                            Toggle::make('metadata.no_body')
                                ->label(__('Send without body'))
                                ->default(false)
                                ->inline(false)
                                ->helperText(__('When enabled, the POST request will be sent without any body content. Useful for APIs that only need a POST trigger (e.g., Emby/Jellyfin scheduled tasks).'))
                                ->hidden(fn (Get $get) => ! $get('metadata.post'))
                                ->live(),
                            Toggle::make('metadata.json_body')
                                ->label(__('Send as JSON body'))
                                ->default(false)
                                ->inline(false)
                                ->live()
                                ->helperText(__('When enabled, variables will be sent as a JSON body instead of form data. Only applies to POST requests.'))
                                ->hidden(fn (Get $get) => ! $get('metadata.post') || $get('metadata.no_body')),

                            Repeater::make('metadata.post_vars')
                                ->label(__('GET/POST variables'))
                                ->schema([
                                    TextInput::make('variable_name')
                                        ->label(__('Variable name'))
                                        ->placeholder(__('variable_name'))
                                        ->helperText(__('Name of the variable to send as GET/POST variable to your webhook URL.'))
                                        ->datalist([
                                            'name',
                                            'uuid',
                                            'url',
                                        ])
                                        ->alphaDash()
                                        ->ascii()
                                        ->required(),
                                    Select::make('value')
                                        ->label(__('Value'))
                                        ->required()
                                        ->options(self::variableOptions())
                                        ->helperText(__('Value to use for this variable.')),
                                ])
                                ->columns(2)
                                ->columnSpanFull()
                                ->addActionLabel('Add GET/POST variable')
                                ->hidden(fn (Get $get) => $get('metadata.post') && $get('metadata.no_body')),
                        ]),
                ])->hidden(fn (Get $get) => $get('metadata.local') !== 'url'),
            Fieldset::make(__('Script Options'))
                ->schema([
                    Repeater::make('metadata.script_vars')
                        ->label(__('Export variables'))
                        ->schema([
                            TextInput::make('export_name')
                                ->label(__('Export name'))
                                ->placeholder(__('VARIABLE_NAME'))
                                ->helperText('Name of the variable to export. Example: VARIABLE_NAME can be used as $VARIABLE_NAME in your script.')
                                ->datalist([
                                    'NAME',
                                    'UUID',
                                    'URL',
                                    'M3U_NAME',
                                    'M3U_UUID',
                                    'M3U_URL',
                                ])
                                ->alphaDash()
                                ->ascii()
                                ->required(),
                            Select::make('value')
                                ->label(__('Value'))
                                ->required()
                                ->options(self::variableOptions())
                                ->helperText(__('Value to use for this variable.')),
                        ])
                        ->columns(2)
                        ->columnSpanFull()
                        ->addActionLabel('Add named export'),
                ])->hidden(fn (Get $get) => $get('metadata.local') !== 'path'),

            Fieldset::make(__('Email Options'))
                ->schema([
                    TextInput::make('metadata.subject')
                        ->label(__('Email Subject'))
                        ->columnSpan(2)
                        ->maxLength(255)
                        ->helperText(__('Subject line for the email (optional).')),
                    Textarea::make('metadata.body')
                        ->label(__('Email Body'))
                        ->columnSpan(2)
                        ->helperText(__('Body content for the email (optional).')),
                    Repeater::make('metadata.email_vars')
                        ->label(__('Email variables'))
                        ->schema([
                            Select::make('value')
                                ->label(__('Value'))
                                ->required()
                                ->columnSpanFull()
                                ->options(self::variableOptions())
                                ->helperText(__('Value to include in the email.')),
                        ])
                        ->columns(2)
                        ->columnSpanFull()
                        ->addActionLabel('Add variable'),
                ])->hidden(fn (Get $get) => $get('metadata.local') !== 'email'),
            Fieldset::make(__('Conditional Settings'))
                ->schema([
                    Repeater::make('conditions')
                        ->label(__('Conditions'))
                        ->schema([
                            Select::make('field')
                                ->label(__('Field'))
                                ->required()
                                ->options(self::variableOptions())
                                ->helperText(__('Field to check condition against.')),
                            Select::make('operator')
                                ->label(__('Condition'))
                                ->required()
                                ->options([
                                    'equals' => 'Equals',
                                    'not_equals' => 'Not equals',
                                    'greater_than' => 'Greater than',
                                    'less_than' => 'Less than',
                                    'greater_than_or_equal' => 'Greater than or equal',
                                    'less_than_or_equal' => 'Less than or equal',
                                    'contains' => 'Contains',
                                    'not_contains' => 'Does not contain',
                                    'starts_with' => 'Starts with',
                                    'ends_with' => 'Ends with',
                                    'is_true' => 'Is true',
                                    'is_false' => 'Is false',
                                    'is_empty' => 'Is empty',
                                    'is_not_empty' => 'Is not empty',
                                ])
                                ->helperText(__('Condition to check.'))
                                ->live(),
                            TextInput::make('value')
                                ->label(__('Value'))
                                ->helperText(__('Value to compare against (not needed for true/false/empty conditions).'))
                                ->hidden(fn (Get $get) => in_array($get('operator'), ['is_true', 'is_false', 'is_empty', 'is_not_empty']))
                                ->required(fn (Get $get) => ! in_array($get('operator'), ['is_true', 'is_false', 'is_empty', 'is_not_empty'])),
                        ])
                        ->columns(3)
                        ->columnSpanFull()
                        ->addActionLabel('Add condition')
                        ->helperText(__('Add conditions that must be met for this post process to execute. All conditions must be true for execution.')),
                ]),
        ];

        return [
            $operation === 'create'
                ? Grid::make()->schema($schema)->columns(2)
                : Section::make(__('Configuration'))
                    ->icon('heroicon-s-pencil-square')
                    ->collapsible()
                    ->collapsed(true)
                    ->compact()
                    ->schema($schema)->columns(2),
        ];
    }

    /**
     * Shared variable/field options used across GET/POST vars, script vars, email vars, and conditions.
     *
     * @return array<string, string>
     */
    private static function variableOptions(): array
    {
        return [
            'id' => 'ID',
            'uuid' => 'UUID',
            'name' => 'Name',
            'url' => 'URL',
            'status' => 'Status',
            'time' => 'Sync time',
            'added_groups' => '# Groups added (Playlist only)',
            'removed_groups' => '# Groups removed (Playlist only)',
            'added_channels' => '# Channels added (Playlist only)',
            'removed_channels' => '# Channels removed (Playlist only)',
            'added_group_names' => 'Group names added (Playlist only)',
            'removed_group_names' => 'Group names removed (Playlist only)',
            'added_channel_names' => 'Channel names added (Playlist only)',
            'removed_channel_names' => 'Channel names removed (Playlist only)',
        ];
    }
}
