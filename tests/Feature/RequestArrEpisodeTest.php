<?php

use App\Jobs\RequestArrEpisode;
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

function makeEpisodeJob(ArrIntegration $integration, int $sonarrSeriesId = 77, int $season = 1, int $episode = 1): RequestArrEpisode
{
    return new RequestArrEpisode(
        integrationId: $integration->id,
        sonarrSeriesId: $sonarrSeriesId,
        seasonNumber: $season,
        episodeNumber: $episode,
        userId: $integration->user_id,
        showTitle: 'Dark',
    );
}

it('monitors and searches the episode when Sonarr has finished indexing', function () {
    $integration = ArrIntegration::factory()->sonarr()->create();

    Http::fake([
        '*/api/v3/episode*' => Http::response([
            ['id' => 301, 'episodeNumber' => 1, 'seasonNumber' => 1, 'title' => 'Secrets'],
        ], 200),
        '*/api/v3/episode/monitor' => Http::response(null, 200),
        '*/api/v3/command' => Http::response(['id' => 1], 201),
    ]);

    makeEpisodeJob($integration)->handle();

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/api/v3/episode/monitor')
        && in_array(301, $r->data()['episodeIds'] ?? []));

    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_ends_with($r->url(), '/api/v3/command')
        && ($r->data()['name'] ?? '') === 'EpisodeSearch');

    NotificationFacade::assertSentTo(
        User::find($integration->user_id),
        DatabaseNotification::class,
    );
});

it('throws when episode is not yet indexed so the queue retries', function () {
    $integration = ArrIntegration::factory()->sonarr()->create();

    Http::fake([
        '*/api/v3/episode*' => Http::response([], 200),
    ]);

    expect(fn () => makeEpisodeJob($integration)->handle())
        ->toThrow(RuntimeException::class, 'not yet indexed');
});

it('sends a failure notification when the job exhausts all retries', function () {
    $integration = ArrIntegration::factory()->sonarr()->create();
    $user = User::find($integration->user_id);

    makeEpisodeJob($integration)->failed(new RuntimeException('Episode S01E01 not yet indexed by Sonarr.'));

    NotificationFacade::assertSentTo($user, DatabaseNotification::class);
});

it('does nothing when the integration no longer exists', function () {
    $integration = ArrIntegration::factory()->sonarr()->create();
    $integrationId = $integration->id;
    $userId = $integration->user_id;
    $integration->delete();

    Http::fake();

    $job = new RequestArrEpisode(
        integrationId: $integrationId,
        sonarrSeriesId: 77,
        seasonNumber: 1,
        episodeNumber: 1,
        userId: $userId,
        showTitle: 'Dark',
    );

    $job->handle();

    Http::assertNothingSent();
});
