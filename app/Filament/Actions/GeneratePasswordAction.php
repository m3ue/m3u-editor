<?php

namespace App\Filament\Actions;

use App\Support\PlaylistAuthPasswordGenerator;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Set;

class GeneratePasswordAction
{
    public static function make(string $field = 'password'): Action
    {
        return Action::make('generatePassword')
            ->label(__('Generate password'))
            ->icon('heroicon-o-arrow-path')
            ->tooltip(__('Generate a secure password'))
            ->action(function (Set $set) use ($field): void {
                $set($field, PlaylistAuthPasswordGenerator::generate());
            });
    }
}
