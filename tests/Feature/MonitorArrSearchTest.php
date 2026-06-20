<?php

use App\Jobs\MonitorArrSearch;
use App\Models\ArrIntegration;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    NotificationFacade::fake();
});

function makeMonitorJob(ArrIntegration $integration, int $contentId = 42): MonitorArrSearch
{
    return new MonitorArrSearch(
        integrationId: $integration->id,
        contentId: $contentId,
        contentTitle: 'Conclave',
        userId: $integration->user_id,
    );
}

it('sends a warning notification when all releases are rejected', function () {
    $integration = ArrIntegration::factory()->radarr()->create();
    $user = User::find($integration->user_id);

    Http::fake([
        '*/api/v3/release*' => Http::response([
            ['title' => 'Conclave.2024.1080p', 'approved' => false, 'rejections' => ['Quality not wanted']],
            ['title' => 'Conclave.2024.4K', 'approved' => false, 'rejections' => ['Quality not wanted']],
        ], 200),
    ]);

    makeMonitorJob($integration)->handle();

    NotificationFacade::assertSentTo($user, DatabaseNotification::class);
});

it('sends a warning notification when the release list is empty', function () {
    $integration = ArrIntegration::factory()->radarr()->create();
    $user = User::find($integration->user_id);

    Http::fake([
        '*/api/v3/release*' => Http::response([], 200),
    ]);

    makeMonitorJob($integration)->handle();

    NotificationFacade::assertSentTo($user, DatabaseNotification::class);
});

it('does not notify when at least one release is approved', function () {
    $integration = ArrIntegration::factory()->radarr()->create();

    Http::fake([
        '*/api/v3/release*' => Http::response([
            ['title' => 'Conclave.2024.4K', 'approved' => true, 'rejections' => []],
            ['title' => 'Conclave.2024.1080p', 'approved' => false, 'rejections' => ['Quality not wanted']],
        ], 200),
    ]);

    makeMonitorJob($integration)->handle();

    NotificationFacade::assertNothingSent();
});

it('does nothing for Sonarr integrations', function () {
    $integration = ArrIntegration::factory()->sonarr()->create();

    Http::fake();

    makeMonitorJob($integration)->handle();

    Http::assertNothingSent();
    NotificationFacade::assertNothingSent();
});

it('does nothing when the integration no longer exists', function () {
    $integration = ArrIntegration::factory()->radarr()->create();
    $integrationId = $integration->id;
    $userId = $integration->user_id;
    $integration->delete();

    Http::fake();

    $job = new MonitorArrSearch(
        integrationId: $integrationId,
        contentId: 42,
        contentTitle: 'Conclave',
        userId: $userId,
    );

    $job->handle();

    Http::assertNothingSent();
    NotificationFacade::assertNothingSent();
});

it('sends a warning notification when the job exhausts all retries', function () {
    $integration = ArrIntegration::factory()->radarr()->create();
    $user = User::find($integration->user_id);

    makeMonitorJob($integration)->failed(new RuntimeException('Connection timed out.'));

    NotificationFacade::assertSentTo($user, DatabaseNotification::class);
});
