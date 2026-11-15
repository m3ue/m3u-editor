<?php

use App\Filament\Clusters\Settings\Pages\ManageSmtpSettings;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('sends a test email when no SMTP authentication is configured', function () {
    Mail::fake();

    // Not calling ->call('save') here: persisting through the shared
    // GeneralSettings object is exercised by other settings tests, and this
    // test only needs the in-memory form state the action reads from.
    Livewire::test(ManageSmtpSettings::class)
        ->fillForm([
            'smtp_host' => 'relay.example.com',
            'smtp_port' => 25,
            'smtp_username' => null,
            'smtp_password' => null,
            'smtp_from_address' => 'no-reply@example.com',
            'smtp_encryption' => null,
        ])
        ->callAction('send_test_email', ['to_email' => 'recipient@example.com'])
        ->assertNotified(__('Test Email Sent'));

    // Mail::fake()'s raw() is a no-op (unlike send()/mailable assertions), so
    // reaching the success notification is itself proof the guard let the
    // no-auth configuration through to Mail::raw() without throwing.
});

it('still requires host and port before sending a test email', function () {
    Livewire::test(ManageSmtpSettings::class)
        ->fillForm([
            'smtp_host' => null,
            'smtp_port' => null,
        ])
        ->callAction('send_test_email', ['to_email' => 'recipient@example.com'])
        ->assertNotified(__('Missing SMTP Fields'));
});
