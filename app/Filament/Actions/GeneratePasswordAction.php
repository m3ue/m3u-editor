<?php

namespace App\Filament\Actions;

use App\Services\PasswordGeneratorService;
use Closure;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Set;

class GeneratePasswordAction
{
    public static function make(string $field = 'password', ?Closure $generator = null): Action
    {
        return Action::make('generatePassword')
            ->label(__('Generate password'))
            ->icon('heroicon-o-arrow-path')
            ->tooltip(__('Generate a secure password'))
            ->action(function (Set $set) use ($field, $generator): void {
                $set($field, $generator ? $generator() : PasswordGeneratorService::generate());
            });
    }
}
