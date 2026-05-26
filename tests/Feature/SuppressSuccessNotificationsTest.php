<?php

use App\Models\User;
use App\Notifications\Notification;
use App\Settings\GeneralSettings;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

beforeEach(function () {
    $this->user = User::factory()->create();
    NotificationFacade::fake();
});

function mockNotificationSettings(bool $suppress): void
{
    $settings = Mockery::mock(GeneralSettings::class);
    $settings->suppress_success_notifications = $suppress;
    app()->instance(GeneralSettings::class, $settings);
}

it('sends a success database notification when suppression is disabled', function () {
    mockNotificationSettings(suppress: false);

    Notification::make()->title('Done')->success()->sendToDatabase($this->user);

    NotificationFacade::assertSentTo($this->user, DatabaseNotification::class);
});

it('suppresses a success database notification when suppression is enabled', function () {
    mockNotificationSettings(suppress: true);

    Notification::make()->title('Done')->success()->sendToDatabase($this->user);

    NotificationFacade::assertNothingSent();
});

it('suppresses a success broadcast notification when suppression is enabled', function () {
    mockNotificationSettings(suppress: true);

    Notification::make()->title('Done')->success()->broadcast($this->user);

    NotificationFacade::assertNothingSent();
});

it('does not suppress a danger notification when suppression is enabled', function () {
    mockNotificationSettings(suppress: true);

    Notification::make()->title('Error')->danger()->sendToDatabase($this->user);

    NotificationFacade::assertSentTo($this->user, DatabaseNotification::class);
});

it('does not suppress a warning notification when suppression is enabled', function () {
    mockNotificationSettings(suppress: true);

    Notification::make()->title('Warning')->warning()->sendToDatabase($this->user);

    NotificationFacade::assertSentTo($this->user, DatabaseNotification::class);
});

it('does not suppress an info notification when suppression is enabled', function () {
    mockNotificationSettings(suppress: true);

    Notification::make()->title('Info')->info()->sendToDatabase($this->user);

    NotificationFacade::assertSentTo($this->user, DatabaseNotification::class);
});
