<?php

namespace App\Filament\Resources\Users;

use App\Models\User;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use STS\FilamentImpersonate\Actions\Impersonate;

class UserResource extends Resource
{
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
            ->where('email', '!=', config('dev.admin_emails')[0] ?? ''); // Hide primary admin user

        return $query;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::UserGroup;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('Username')
                    ->required(),
                Forms\Components\TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                Fieldset::make('User Permissions')
                    ->columns(1)
                    ->schema([
                        Forms\Components\CheckboxList::make('permissions')
                            ->label('Select Permissions')
                            ->options(User::getAvailablePermissions())
                            ->bulkToggleable()
                            ->descriptions([
                                'use_proxy' => 'Allow this user to access proxy features and stream via the m3u-proxy server',
                                'use_integrations' => 'Allow this user to access media server integrations and related features',
                                'use_tools' => 'Allow this user to access tools like API Tokens and Post Processing',
                                'use_stream_file_sync' => 'Allow this user to access stream file sync features',
                            ])
                            ->columnSpanFull()
                            ->gridDirection('row')
                            ->columns(2),
                    ]),
                Forms\Components\Toggle::make('update_password')
                    ->label('Update Password')
                    ->default(false)
                    ->live()
                    ->hiddenOn('create')
                    ->dehydrated(false)
                    ->columnSpanFull(),
                // Forms\Components\DateTimePicker::make('email_verified_at'),
                Forms\Components\TextInput::make('password')
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
                //     ->label('Avatar')
                //     ->circular()
                //     ->imageHeight(40)
                //     ->circular(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Username')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                Tables\Columns\TextColumn::make('permissions')
                    ->label('Permissions')
                    ->badge()
                    ->toggleable(),
                // Tables\Columns\TextColumn::make('email_verified_at')
                //     ->dateTime()
                //     ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                // Tables\Columns\TextColumn::make('avatar_url')
                //     ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\DeleteAction::make()
                    ->button()->hiddenLabel()->size('sm'),
                Actions\EditAction::make()
                    ->button()->hiddenLabel()->size('sm'),
                Impersonate::make('Impersonate User')
                    ->color('warning')
                    ->tooltip('Login as this user')
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Actions\BulkActionGroup::make([
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
}
