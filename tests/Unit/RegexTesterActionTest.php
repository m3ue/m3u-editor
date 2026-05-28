<?php

use App\Filament\Actions\RegexTesterAction;
use Filament\Actions\Action;

it('builds the regex tester action', function (): void {
    $action = RegexTesterAction::make(samplesContext: 'channels');

    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->getName())->toBe('test-regex');
});
