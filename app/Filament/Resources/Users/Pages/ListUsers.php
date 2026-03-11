<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected ?string $subheading = 'Manage users that can access and use the application. Each user will have their own playlists, channels, series, and other resources. Some features may be restricted based on user roles (such as global settings).';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->icon('heroicon-o-user-plus'),
            Actions\Action::make('notify')
                ->label('Notify All Users')
                ->icon('heroicon-o-bell-alert')
                ->modalIcon('heroicon-o-bell-alert')
                ->schema([
                    TextInput::make('subject')
                        ->label('Notification Subject')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('message')
                        ->label('Notification Message')
                        ->required()
                        ->maxLength(255),
                    Select::make('type')
                        ->label('Notification Type')
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
                        ->label('Notify Myself')
                        ->helperText('Send the notification to yourself as well (for testing purposes)'),
                ])
                ->action(function (array $data) {
                    // Send notification to all users
                    $users = User::when(! $data['notify_self'], function ($query) {
                        $query->where('id', '!=', auth()->id());
                    })->get();
                    foreach ($users as $user) {
                        Notification::make()
                            ->{$data['type']}()
                            ->title($data['subject'])
                            ->body($data['message'])
                            ->broadcast($user)
                            ->sendToDatabase($user);
                    }
                })->after(function (array $data) {
                    $usersCount = User::count();
                    Notification::make()
                        ->success()
                        ->title('Notifications Sent')
                        ->body("Sent notification to {$usersCount} users.")
                        ->send();
                }),
        ];
    }
}
