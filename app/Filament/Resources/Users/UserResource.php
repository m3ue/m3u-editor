<?php

namespace App\Filament\Resources\Users;

use App\Filament\Concerns\HasCopilotSupport;
use App\Models\User;
use App\Services\DateFormatService;
use BackedEnum;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use STS\FilamentImpersonate\Actions\Impersonate;

class UserResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;

    protected static ?string $model = User::class;

    /**
     * Check if the user can access this page.
     * Only admin users can access the Preferences page.
     */
    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->where('is_admin', false); // Hide admin users from the list

        return $query;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::UserGroup;

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function getModelLabel(): string
    {
        return __('User');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Users');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Username'))
                    ->required(),
                TextInput::make('email')
                    ->label(__('Email address'))
                    ->email()
                    ->required(),
                Fieldset::make(__('User Permissions'))
                    ->columns(1)
                    ->schema([
                        Forms\Components\CheckboxList::make('permissions')
                            ->label(__('Select Permissions'))
                            ->options(User::getAvailablePermissions())
                            ->bulkToggleable()
                            ->descriptions([
                                'use_proxy' => 'Allow this user to access proxy features and stream via the m3u-proxy server',
                                'use_integrations' => 'Allow this user to access media server integrations and related features',
                                'use_tools' => 'Allow this user to access tools like API Tokens and Post Processing',
                                'use_stream_file_sync' => 'Allow this user to access stream file sync features',
                                'use_scrubber' => 'Allow this user to access the Channel Scrubber feature',
                                'view_release_logs' => 'Allow this user to view release logs and the release logs page',
                                'use_ai_copilot' => 'Allow this user to access and use the AI Copilot chat assistant',
                                'use_dvr' => 'Allow this user to access DVR features, manage recording rules, and view recordings',
                            ])
                            ->columnSpanFull()
                            ->gridDirection('row')
                            ->columns(2),
                    ]),
                Toggle::make('must_change_password')
                    ->label(__('Force password change on next login'))
                    ->helperText(__('When enabled, the user will be prompted to set a new password before they can use the application.'))
                    ->columnSpanFull(),
                Toggle::make('update_password')
                    ->label(__('Update Password'))
                    ->default(false)
                    ->live()
                    ->hiddenOn('create')
                    ->dehydrated(false)
                    ->columnSpanFull(),
                // Forms\Components\DateTimePicker::make('email_verified_at'),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->columnSpanFull()
                    ->hidden(fn ($get, $record) => ! $record ? false : ! $get('update_password'))
                    ->required(),
                // Forms\Components\TextInput::make('avatar_url')
                //     ->url(),
                // Forms\Components\Textarea::make('app_authentication_secret')
                //     ->columnSpanFull(),
                // Forms\Components\Textarea::make('app_authentication_recovery_codes')
                //     ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Tables\Columns\ImageColumn::make('avatar_url')
                //     ->label(__('Avatar'))
                //     ->circular()
                //     ->imageHeight(40)
                //     ->circular(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Username'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('Email address'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('permissions')
                    ->label(__('Permissions'))
                    ->badge()
                    ->toggleable(),
                // Tables\Columns\TextColumn::make('email_verified_at')
                //     ->dateTime()
                //     ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                // Tables\Columns\TextColumn::make('avatar_url')
                //     ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\ActionGroup::make([
                    Actions\Action::make('notify')
                        ->label(__('Notify User'))
                        ->icon('heroicon-o-bell-alert')
                        ->modalIcon('heroicon-o-bell-alert')
                        ->schema(self::getNotifySchema())
                        ->action(function (User $record, array $data) {
                            Notification::make()
                                ->{$data['type']}()
                                ->title($data['subject'])
                                ->body($data['message'])
                                ->broadcast($record)
                                ->sendToDatabase($record);

                            // See if sending to self to test notification
                            if ($data['notify_self']) {
                                Notification::make()
                                    ->{$data['type']}()
                                    ->title($data['subject'])
                                    ->body($data['message'])
                                    ->broadcast(auth()->user())
                                    ->sendToDatabase(auth()->user());
                            }
                        })
                        ->after(function (User $record, array $data) {
                            Notification::make()
                                ->success()
                                ->title(__('Notifications Sent'))
                                ->body("Sent notification to {$record->name}.")
                                ->send();
                        }),
                    Impersonate::make('Impersonate User')
                        ->color('warning')
                        ->tooltip(__('Login as this user')),
                    Actions\DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm'),
                Actions\EditAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('notify')
                        ->label(__('Notify Users'))
                        ->icon('heroicon-o-bell-alert')
                        ->modalIcon('heroicon-o-bell-alert')
                        ->schema(self::getNotifySchema())
                        ->action(function (Collection $records, array $data) {
                            foreach ($records as $user) {
                                Notification::make()
                                    ->{$data['type']}()
                                    ->title($data['subject'])
                                    ->body($data['message'])
                                    ->broadcast($user)
                                    ->sendToDatabase($user);
                            }

                            // See if sending to self to test notification
                            if ($data['notify_self']) {
                                Notification::make()
                                    ->{$data['type']}()
                                    ->title($data['subject'])
                                    ->body($data['message'])
                                    ->broadcast(auth()->user())
                                    ->sendToDatabase(auth()->user());
                            }
                        })
                        ->after(function (Collection $records, array $data) {
                            Notification::make()
                                ->success()
                                ->title(__('Notifications Sent'))
                                ->body("Sent notification to {$records->count()} users.")
                                ->send();
                        }),
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
            'index' => Pages\ListUsers::route('/'),
            // 'create' => Pages\CreateUser::route('/create'),
            // 'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    private static function getNotifySchema(): array
    {
        return [
            TextInput::make('subject')
                ->label(__('Notification Subject'))
                ->required()
                ->maxLength(255),
            Textarea::make('message')
                ->label(__('Notification Message'))
                ->required()
                ->maxLength(255),
            Select::make('type')
                ->label(__('Notification Type'))
                ->options([
                    'info' => 'Info',
                    'success' => 'Success',
                    'warning' => 'Warning',
                    'danger' => 'Danger',
                ])
                ->native(false)
                ->default('info')
                ->required(),
            Toggle::make('notify_self')
                ->label(__('Notify Myself'))
                ->helperText(__('Send the notification to yourself as well (for testing purposes)')),
        ];
    }
}
