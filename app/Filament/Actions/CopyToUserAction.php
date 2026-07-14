<?php

namespace App\Filament\Actions;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

class CopyToUserAction
{
    public static function make(): Action
    {
        return Action::make('copy-to-user')
            ->label(__('Copy to User'))
            ->icon('heroicon-o-user-plus')
            ->modalHeading(__('Copy to Users'))
            ->modalDescription(__('Select one or more users to copy this record to.'))
            ->modalSubmitActionLabel(__('Copy'))
            ->visible(fn (): bool => (bool) auth()->user()?->is_admin)
            ->schema([
                Select::make('user_ids')
                    ->label(__('Users'))
                    ->multiple()
                    ->required()
                    ->searchable()
                    ->options(fn (): array => User::query()
                        ->where('id', '!=', auth()->id())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray()
                    ),
            ])
            ->action(function (Model $record, array $data): void {
                $copied = 0;

                // Exclude any withCount attributes injected by Filament's table query
                $countKeys = array_values(array_filter(
                    array_keys($record->getAttributes()),
                    fn (string $key): bool => str_ends_with($key, '_count'),
                ));

                foreach ($data['user_ids'] as $userId) {
                    $replica = $record->replicate($countKeys);
                    $replica->user_id = (int) $userId;
                    $replica->save();
                    $copied++;
                }

                Notification::make()
                    ->success()
                    ->title(__('Copied successfully'))
                    ->body($copied === 1
                        ? __('Copied to 1 user.')
                        : __('Copied to :count users.', ['count' => $copied])
                    )
                    ->send();
            });
    }
}
