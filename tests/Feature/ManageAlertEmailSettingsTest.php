<?php

use App\Filament\Clusters\Settings\Pages\ManageAlertSettings;
use App\Mail\AlertMail;
use App\Models\User;
use App\Services\AlertService;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

function configureSavedSmtp(?string $host = 'relay.example.com', ?int $port = 25): void
{
    $settings = app(GeneralSettings::class);
    $settings->smtp_host = $host;
    $settings->smtp_port = $port;
    $settings->save();
}

it('saves the email alert toggle and recipients', function () {
    Livewire::test(ManageAlertSettings::class)
        ->fillForm([
            'email_alerts_enabled' => true,
            'email_alerts_recipients' => ['owner@example.com', 'ops@example.com'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(GeneralSettings::class);
    $settings->refresh();

    expect($settings->email_alerts_enabled)->toBeTrue()
        ->and($settings->email_alerts_recipients)->toBe(['owner@example.com', 'ops@example.com']);
});

it('rejects invalid recipient addresses', function () {
    Livewire::test(ManageAlertSettings::class)
        ->fillForm([
            'email_alerts_enabled' => true,
            'email_alerts_recipients' => ['not-an-email'],
        ])
        ->call('save')
        ->assertHasFormErrors(['email_alerts_recipients.0']);
});

it('requires a recipient when email alerts are enabled', function () {
    Livewire::test(ManageAlertSettings::class)
        ->fillForm([
            'email_alerts_enabled' => true,
            'email_alerts_recipients' => [],
        ])
        ->call('save')
        ->assertHasFormErrors(['email_alerts_recipients']);
});

it('sends a test alert email to the entered recipients', function () {
    Mail::fake();
    configureSavedSmtp();

    Livewire::test(ManageAlertSettings::class)
        ->fillForm([
            'email_alerts_enabled' => true,
            'email_alerts_recipients' => ['owner@example.com'],
        ])
        ->callAction(TestAction::make('test_email_alert')->schemaComponent())
        ->assertNotified(__('Test Alert Sent'));

    Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => $mail->hasTo('owner@example.com')
        && $mail->emailSubject === AlertService::EMAIL_SUBJECT);
});

it('warns instead of sending a test alert when smtp is not configured', function () {
    Mail::fake();
    configureSavedSmtp(host: null, port: null);

    Livewire::test(ManageAlertSettings::class)
        ->fillForm([
            'email_alerts_enabled' => true,
            'email_alerts_recipients' => ['owner@example.com'],
        ])
        ->callAction(TestAction::make('test_email_alert')->schemaComponent())
        ->assertNotified(__('SMTP Not Configured'));

    Mail::assertNothingSent();
});
