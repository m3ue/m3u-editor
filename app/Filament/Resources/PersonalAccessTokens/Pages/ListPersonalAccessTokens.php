<?php

namespace App\Filament\Resources\PersonalAccessTokens\Pages;

use App\Filament\Resources\PersonalAccessTokens\PersonalAccessTokenResource;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPersonalAccessTokens extends ListRecords
{
    protected static string $resource = PersonalAccessTokenResource::class;

    protected ?string $subheading = 'Manage your API tokens. Tokens allow you to authenticate API requests for certain API actions.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver()
                ->label('Add new API Token')
                ->schema(PersonalAccessTokenResource::getForm())
                ->color('primary')
                ->action(function (array $data) {
                    // Create the personal access token
                    $token = auth()->user()->createToken(
                        name: $data['name'],
                        abilities: $data['abilities'],
                        expiresAt: $data['expires_at'] ? Carbon::parse($data['expires_at']) : null
                    );
                    Notification::make()
                        ->success()
                        ->title('API Token Created')
                        ->body("Copy this token and store it in a safe place, it won't be shown again: {$token->plainTextToken}")
                        ->persistent()
                        ->send();
                })
                ->requiresConfirmation()
                ->modalWidth('2xl')
                ->modalIcon('heroicon-o-key')
                ->modalDescription('Enter a name for the new API token, and select the permissions it should have. Permissions can be changed later.')
                ->modalSubmitActionLabel('Create Token'),
            Actions\Action::make('view_api_docs')
                ->label('API Docs')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->iconPosition('after')
                ->color('gray')
                ->url('/docs/api')
                ->openUrlInNewTab(true),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('tokenable_id', auth()->id());
    }
}
