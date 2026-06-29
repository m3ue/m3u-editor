<?php

namespace App\Filament\Resources\AedProfiles;

use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\AedProfiles\Pages\CreateAedProfile;
use App\Filament\Resources\AedProfiles\Pages\EditAedProfile;
use App\Filament\Resources\AedProfiles\Pages\ListAedProfiles;
use App\Models\AedProfile;
use App\Services\AedExtractorService;
use App\Services\DateFormatService;
use App\Traits\HasUserFiltering;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class AedProfileResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
    use HasUserFiltering;

    protected static ?string $model = AedProfile::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $label = 'AED Profile';

    protected static ?string $pluralLabel = 'AED Profiles';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('EPG');
    }

    public static function getModelLabel(): string
    {
        return __('AED Profile');
    }

    public static function getPluralModelLabel(): string
    {
        return __('AED Profiles');
    }

    public static function getNavigationSort(): ?int
    {
        return 10;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::getForm());
    }

    public static function getForm(): array
    {
        return [
            Section::make(__('Profile'))
                ->description(__('Name this AED profile so it can be reused across channels and groups.'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('Profile Name'))
                        ->required()
                        ->maxLength(255)
                        ->placeholder(__('e.g. DAZN PPV')),
                ]),

            Section::make(__('Source Extraction'))
                ->description(__('Define regex patterns to extract event data from the channel title. Capture group 1 is used when present; otherwise the full match.'))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('title_regex')
                            ->label(__('Title Regex'))
                            ->placeholder(__('^(.*?)\\\\s*\\\\[DAZN\\\\]'))
                            ->helperText(__('Extracts the event title. Leave blank to use the full channel title.'))
                            ->maxLength(500),

                        TextInput::make('team_delimiter')
                            ->label(__('Team Delimiter (Optional)'))
                            ->placeholder(__('vs.'))
                            ->helperText(__('Split the extracted title into {team1} / {team2} using this delimiter.'))
                            ->maxLength(20),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('time_regex')
                            ->label(__('Time Regex'))
                            ->placeholder('\((\d{1,2}:\d{2}\s*[AP]M)\s+ET\)')
                            ->helperText(__('Extracts the start time string. Capture group 1 recommended.'))
                            ->maxLength(500),

                        TextInput::make('time_format')
                            ->label(__('Time Format'))
                            ->placeholder(__('g:i A|H:i'))
                            ->helperText(__('PHP date format(s) to parse the extracted time. Separate multiple with |'))
                            ->maxLength(100),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('date_regex')
                            ->label(__('Date Regex (Leave Blank if Entry Contains no Date)'))
                            ->placeholder('\((\d{2}\.\d{2})\s')
                            ->helperText(__('Extracts the start date. Leave blank if the title contains no date.'))
                            ->maxLength(500),

                        TextInput::make('date_format')
                            ->label(__('Date Format (Leave Blank if Entry Contains no Date)'))
                            ->placeholder(__('m.d'))
                            ->helperText(__('PHP date format(s) to parse the extracted date. Separate multiple with |'))
                            ->maxLength(100),
                    ]),

                    Grid::make(2)->schema([
                        Select::make('source_timezone')
                            ->label(__('Timezone of Source'))
                            ->options(fn () => collect(timezone_identifiers_list())->mapWithKeys(fn ($tz) => [$tz => $tz]))
                            ->searchable()
                            ->default('UTC'),

                        TextInput::make('logo_url')
                            ->label(__('Logo URL (Optional)'))
                            ->url()
                            ->maxLength(500)
                            ->placeholder(__('https://example.com/logo.png')),
                    ]),
                ])
                ->collapsible(),

            Section::make(__('Output Format'))
                ->description(__('Define how the extracted data is formatted in the generated EPG programme.'))
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('output_timezone')
                            ->label(__('Output Timezone'))
                            ->options(fn () => collect(timezone_identifiers_list())->mapWithKeys(fn ($tz) => [$tz => $tz]))
                            ->searchable()
                            ->default('UTC'),

                        TextInput::make('event_duration_minutes')
                            ->label(__('Event Duration (minutes)'))
                            ->numeric()
                            ->default(180)
                            ->minValue(1)
                            ->maxValue(1440)
                            ->helperText(__('How long the generated EPG event lasts (default: 180 = 3 hours).')),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('title_format')
                            ->label(__('Title Output Format'))
                            ->default('{title}')
                            ->maxLength(500)
                            ->helperText(__('Available: {title}, {team1}, {team2}, {channel}, {date}, {time}')),

                        TextInput::make('description_format')
                            ->label(__('Description Output Format'))
                            ->placeholder('{title} — {date} at {time}')
                            ->maxLength(500)
                            ->helperText(__('Leave blank to use the title. Same variables as title format.')),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('pre_event_format')
                            ->label(__('Pre-Event Format'))
                            ->default('Live in {time_until}: {title}')
                            ->maxLength(500)
                            ->helperText(__('Padding slots before the event. Available: {time_until}, {title}, {channel}, {date}, {time}')),

                        TextInput::make('post_event_format')
                            ->label(__('Post-Event Format'))
                            ->default('Signing Off')
                            ->maxLength(500)
                            ->helperText(__('Padding slots after the event ends. Available: {title}, {channel}, {date}, {time}')),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('no_event_format')
                            ->label(__('No Event Format (Optional)'))
                            ->default('{channel}')
                            ->maxLength(500)
                            ->helperText(__('Used when regex extraction fails entirely. {channel} = original channel title.')),

                        TextInput::make('category')
                            ->label(__('EPG Category (Optional)'))
                            ->placeholder(__('Sports'))
                            ->maxLength(100),
                    ]),
                ])
                ->collapsible(),

            Section::make(__('Test Extraction'))
                ->description(__('Paste a sample channel title to verify your regex patterns.'))
                ->schema([
                    Textarea::make('_test_title')
                        ->label(__('Sample Channel Title'))
                        ->placeholder(__('PPV 1: Tommy Fury vs. Eddie Hall [DAZN] (06.13 13:00 ET / 18:00 BST)'))
                        ->rows(2)
                        ->dehydrated(false),

                    Actions::make([
                        Action::make('test_extraction')
                            ->label(__('Test Extraction'))
                            ->icon('heroicon-o-beaker')
                            ->color('gray')
                            ->action(function (array $data, $livewire) {
                                $sampleTitle = $livewire->data['_test_title'] ?? '';
                                if (empty($sampleTitle)) {
                                    Notification::make()
                                        ->title(__('Please enter a sample channel title first.'))
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                $profile = new AedProfile;
                                $profile->title_regex = $livewire->data['title_regex'] ?? null;
                                $profile->time_regex = $livewire->data['time_regex'] ?? null;
                                $profile->time_format = $livewire->data['time_format'] ?? null;
                                $profile->source_timezone = $livewire->data['source_timezone'] ?? 'UTC';
                                $profile->date_regex = $livewire->data['date_regex'] ?? null;
                                $profile->date_format = $livewire->data['date_format'] ?? null;
                                $profile->team_delimiter = $livewire->data['team_delimiter'] ?? null;
                                $profile->output_timezone = $livewire->data['output_timezone'] ?? 'UTC';
                                $profile->event_duration_minutes = (int) ($livewire->data['event_duration_minutes'] ?? 180);
                                $profile->title_format = $livewire->data['title_format'] ?? '{title}';
                                $profile->description_format = $livewire->data['description_format'] ?? null;
                                $profile->no_event_format = $livewire->data['no_event_format'] ?? '{channel}';

                                $service = app(AedExtractorService::class);
                                $result = $service->extract($profile, $sampleTitle);

                                if ($result === null) {
                                    Notification::make()
                                        ->title(__('No match'))
                                        ->body(__('Title regex did not match the sample. Check your pattern.'))
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                $body = "Title: {$result->title}";
                                if ($result->description) {
                                    $body .= "\nDescription: {$result->description}";
                                }
                                if ($result->hasTime()) {
                                    $body .= "\nStart: {$result->start->format('Y-m-d H:i T')}";
                                    $body .= "\nEnd: {$result->end->format('Y-m-d H:i T')}";
                                } else {
                                    $body .= "\nTime: Could not extract (will use repeating dummy slots)";
                                }

                                Notification::make()
                                    ->title(__('Extraction successful'))
                                    ->body($body)
                                    ->success()
                                    ->send();
                            }),
                    ]),
                ])
                ->collapsible()
                ->collapsed(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('channels_count')
                    ->label(__('Channels'))
                    ->counts('channels')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('groups_count')
                    ->label(__('Groups'))
                    ->counts('groups')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('event_duration_minutes')
                    ->label(__('Duration (min)'))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('output_timezone')
                    ->label(__('Output TZ'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            'index' => ListAedProfiles::route('/'),
            // 'create' => CreateAedProfile::route('/create'),
            // 'edit' => EditAedProfile::route('/{record}/edit'),
        ];
    }
}
