<?php

namespace App\Filament\Resources\Networks;

use App\Filament\Resources\Networks\Pages\CreateNetwork;
use App\Filament\Resources\Networks\Pages\EditNetwork;
use App\Filament\Resources\Networks\Pages\ListNetworks;
use App\Models\Network;
use App\Services\NetworkScheduleService;
use App\Traits\HasUserFiltering;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class NetworkResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = Network::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Networks';

    protected static ?string $modelLabel = 'Network';

    protected static ?string $pluralModelLabel = 'Networks';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 110;

    public static function getRecordTitle(?Model $record): string|null|Htmlable
    {
        return $record?->name;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Network Configuration')
                    ->description('Configure your pseudo-TV network channel')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Network Name')
                                ->placeholder('e.g., Movie Classics, 80s TV, Kids Zone')
                                ->required()
                                ->maxLength(255),

                            TextInput::make('channel_number')
                                ->label('Channel Number')
                                ->numeric()
                                ->placeholder('e.g., 100')
                                ->helperText('Optional channel number for EPG')
                                ->minValue(1),
                        ]),

                        Textarea::make('description')
                            ->label('Description')
                            ->placeholder('A channel dedicated to classic movies from the golden age of cinema')
                            ->rows(2)
                            ->maxLength(1000),

                        TextInput::make('logo')
                            ->label('Logo URL')
                            ->placeholder('https://example.com/logo.png')
                            ->url()
                            ->maxLength(500),
                    ]),

                Section::make('Schedule Settings')
                    ->description('Control how content is scheduled on this network')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('schedule_type')
                                ->label('Schedule Type')
                                ->options([
                                    'sequential' => 'Sequential (play in order)',
                                    'shuffle' => 'Shuffle (randomized)',
                                ])
                                ->default('sequential')
                                ->helperText('How content is ordered in the schedule')
                                ->native(false),

                            Toggle::make('loop_content')
                                ->label('Loop Content')
                                ->helperText('Restart from beginning when all content has played')
                                ->default(true),
                        ]),

                        Select::make('media_server_integration_id')
                            ->label('Media Server')
                            ->relationship('mediaServerIntegration', 'name')
                            ->helperText('Optional: Link to a media server for content source')
                            ->nullable()
                            ->native(false),

                        Toggle::make('enabled')
                            ->label('Enabled')
                            ->helperText('Disable to stop generating schedule without deleting')
                            ->default(true),
                    ]),

                Section::make('EPG Output')
                    ->description('EPG URL for IPTV players')
                    ->schema([
                        TextInput::make('epg_url')
                            ->label('EPG URL')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn ($record) => $record?->epg_url ?? 'Save network first')
                            ->suffixAction(
                                \Filament\Forms\Components\Actions\Action::make('copy')
                                    ->icon('heroicon-o-clipboard')
                                    ->action(function ($state, $livewire) {
                                        $livewire->js("navigator.clipboard.writeText('{$state}')");
                                        Notification::make()
                                            ->success()
                                            ->title('Copied to clipboard')
                                            ->send();
                                    })
                            ),

                        TextInput::make('schedule_info')
                            ->label('Schedule Info')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(function ($record) {
                                if (! $record) {
                                    return 'Not generated';
                                }
                                $count = $record->programmes()->count();
                                $last = $record->programmes()->latest('end_time')->first();

                                return $count > 0
                                    ? "{$count} programmes until ".($last?->end_time?->format('M j, Y H:i') ?? 'unknown')
                                    : 'No programmes - generate schedule first';
                            }),
                    ])
                    ->visibleOn('edit'),

                Section::make('Stream Output')
                    ->description('Live stream URL for IPTV players')
                    ->schema([
                        TextInput::make('stream_url')
                            ->label('Stream URL')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn ($record) => $record?->stream_url ?? 'Save network first')
                            ->suffixAction(
                                \Filament\Forms\Components\Actions\Action::make('copy_stream')
                                    ->icon('heroicon-o-clipboard')
                                    ->action(function ($state, $livewire) {
                                        $livewire->js("navigator.clipboard.writeText('{$state}')");
                                        Notification::make()
                                            ->success()
                                            ->title('Copied to clipboard')
                                            ->send();
                                    })
                            ),
                    ])
                    ->visibleOn('edit'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('channel_number')
                    ->label('Ch #')
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('schedule_type')
                    ->label('Schedule')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'shuffle' => 'warning',
                        'sequential' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('network_content_count')
                    ->label('Content')
                    ->counts('networkContent')
                    ->sortable(),

                ToggleColumn::make('enabled')
                    ->label('Enabled'),

                TextColumn::make('schedule_generated_at')
                    ->label('Schedule Generated')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->placeholder('Never'),

                TextColumn::make('mediaServerIntegration.name')
                    ->label('Media Server')
                    ->placeholder('None'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('schedule_type')
                    ->options([
                        'sequential' => 'Sequential',
                        'shuffle' => 'Shuffle',
                    ]),
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('Enabled'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('generateSchedule')
                        ->label('Generate Schedule')
                        ->icon('heroicon-o-calendar')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Generate Schedule')
                        ->modalDescription('This will generate a 7-day programme schedule for this network. Existing future programmes will be replaced.')
                        ->action(function (Network $record) {
                            $service = app(NetworkScheduleService::class);
                            $service->generateSchedule($record);

                            Notification::make()
                                ->success()
                                ->title('Schedule Generated')
                                ->body("Generated programme schedule for {$record->name}")
                                ->send();
                        }),

                    EditAction::make(),

                    DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('generateAllSchedules')
                        ->label('Generate Schedules')
                        ->icon('heroicon-o-calendar')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $service = app(NetworkScheduleService::class);
                            foreach ($records as $record) {
                                $service->generateSchedule($record);
                            }

                            Notification::make()
                                ->success()
                                ->title('Schedules Generated')
                                ->body('Generated schedules for '.$records->count().' networks.')
                                ->send();
                        }),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\NetworkContentRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNetworks::route('/'),
            'create' => CreateNetwork::route('/create'),
            'edit' => EditNetwork::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', Auth::id());
    }
}
