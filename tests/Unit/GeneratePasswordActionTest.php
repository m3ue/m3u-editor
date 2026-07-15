<?php

use App\Filament\Actions\GeneratePasswordAction;
use Filament\Actions\Action;

it('builds the generate password action', function (): void {
    $action = GeneratePasswordAction::make();

    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->getName())->toBe('generatePassword');
});

it('accepts a custom generator closure', function (): void {
    $action = GeneratePasswordAction::make(generator: fn (): string => 'custom-secret');

    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->getName())->toBe('generatePassword');
});
