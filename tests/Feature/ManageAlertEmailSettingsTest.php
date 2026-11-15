<?php

use App\Filament\Clusters\Settings\Pages\ManageAlertSettings;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('sends a test email when no SMTP authentication is configured', function () {
    Mail::fake();

    Livewire::test(ManageAlertSettings::class)
        ->fillForm([
            'smtp_host' => 'relay.example.com',
            'smtp_port' => 25,
            'smtp_username' => null,
            'smtp_password' => null,
            'smtp_from_address' => 'no-reply@example.com',
            'smtp_encryption' => null,
        ])
        ->callAction(
            TestAction::make('send_test_email')->schemaComponent('email-settings'),
            ['to_email' => 'recipient@example.com'],
        )
        ->assertNotified(__('Test Email Sent'));
});

it('still requires host and port before sending a test email', function () {
    Livewire::test(ManageAlertSettings::class)
        ->fillForm([
            'smtp_host' => null,
            'smtp_port' => null,
        ])
        ->callAction(
            TestAction::make('send_test_email')->schemaComponent('email-settings'),
            ['to_email' => 'recipient@example.com'],
        )
        ->assertNotified(__('Missing SMTP Fields'));
});
