<?php

/**
 * Issue #1746: a syntactically valid cron expression with no calendar occurrence (e.g.
 * "0 0 31 2 *", a February 31st) passed CronExpression::isValidExpression()'s syntax-only
 * check, then threw a RuntimeException from getNextRunDate() - crashing the Backups
 * settings form's live helper-text render instead of surfacing a field validation error.
 */

use App\Filament\Clusters\Settings\Pages\ManageBackupSettings;
use App\Models\User;
use App\Rules\Cron;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

it('rejects a syntactically valid cron expression with no calendar occurrence', function () {
    $validator = Validator::make(
        ['schedule' => '0 0 31 2 *'],
        ['schedule' => [new Cron]]
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('schedule'))->toContain('never occurs on a calendar');
});

it('accepts a valid leap-day cron expression', function () {
    $validator = Validator::make(
        ['schedule' => '0 0 29 2 *'],
        ['schedule' => [new Cron]]
    );

    expect($validator->fails())->toBeFalse();
});

it('still rejects a syntactically invalid cron expression', function () {
    $validator = Validator::make(
        ['schedule' => 'not a cron'],
        ['schedule' => [new Cron]]
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('schedule'))->toContain('must be a valid CRON expression');
});

it('does not crash the Backups settings form render with an impossible cron date', function () {
    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::test(ManageBackupSettings::class)
        ->fillForm([
            'auto_backup_database' => true,
            'auto_backup_database_schedule' => '0 0 31 2 *',
        ]);

    $component->assertHasNoErrors();
});
