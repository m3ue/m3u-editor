<?php

use App\Jobs\AddAioStreamsItemsToLibrary;
use App\Jobs\ResolveAioStreamsChannel;
use App\Jobs\ResolveAioStreamsEpisode;
use App\Livewire\AioStreamsBrowse;
use App\Models\AioStreamsMatchProfile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Series;
use App\Models\User;
use App\Services\AioStreamsLibraryService;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);

    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
        'aiostreams_tmdb_enrich' => false,
    ]);

    Http::fake([
        '*/meta/movie/tt1.json' => Http::response(['meta' => ['id' => 'tt1', 'name' => 'Bulk Movie', 'releaseInfo' => '2020']]),
        '*/meta/series/tt2.json' => Http::response(['meta' => [
            'id' => 'tt2',
            'name' => 'Bulk Series',
            'videos' => [
                ['season' => 0, 'episode' => 1, 'title' => 'Special', 'released' => '2019-01-01'],
                ['season' => 1, 'episode' => 1, 'title' => 'S1E1', 'released' => '2020-01-01'],
                ['season' => 2, 'episode' => 1, 'title' => 'S2E1', 'released' => '2021-01-01'],
                ['season' => 2, 'episode' => 2, 'title' => 'S2E2', 'released' => '2099-01-01'],
                ['season' => 3, 'episode' => 1, 'title' => 'S3E1', 'released' => '2099-06-01'],
            ],
        ]]),
        '*/meta/movie/missing.json' => Http::response([], 404),
        'api.themoviedb.org/*' => Http::response([], 404),
        // The Stremio-addon fallback fetchMeta() tries for a missing title.
        '*' => Http::response([], 404),
    ]);
});

function bulkAddProfile(User $user, string $name = 'Prefer 4K'): AioStreamsMatchProfile
{
    return AioStreamsMatchProfile::create(['user_id' => $user->id, 'name' => $name, 'rules' => []]);
}

it('adds a movie straight away with no picker when the user has no profiles', function () {
    Bus::fake([ResolveAioStreamsChannel::class]);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'movie', 'tt1')
        ->callAction(TestAction::make('addToLibrary'));

    $channel = Channel::where('aio_item_id', 'tt1')->firstOrFail();

    expect($channel->aio_match_profile_id)->toBeNull();
});

it('stores the profile picked in the add to library modal', function () {
    Bus::fake([ResolveAioStreamsChannel::class]);
    $profile = bulkAddProfile($this->user);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'movie', 'tt1')
        ->callAction(TestAction::make('addToLibrary'), ['profile_id' => $profile->id]);

    expect(Channel::where('aio_item_id', 'tt1')->value('aio_match_profile_id'))->toBe($profile->id);
});

it('stores the picked profile on a series added from the modal', function () {
    $profile = bulkAddProfile($this->user);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'series', 'tt2')
        ->callAction(TestAction::make('addToLibrary'), ['profile_id' => $profile->id]);

    expect(Series::where('aio_item_id', 'tt2')->value('aio_match_profile_id'))->toBe($profile->id);
});

it('queues the bulk add with only valid, de-duplicated items', function () {
    Bus::fake([AddAioStreamsItemsToLibrary::class]);
    $profile = bulkAddProfile($this->user);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->callAction(TestAction::make('bulkAddToLibrary')->arguments(['items' => [
            ['type' => 'movie', 'id' => 'tt1', 'name' => 'ignored'],
            ['type' => 'movie', 'id' => 'tt1'],
            ['type' => 'series', 'id' => 'tt2'],
            ['type' => 'channel', 'id' => 'tt3'],
            ['type' => 'movie', 'id' => ''],
            'junk',
        ]]), ['profile_id' => $profile->id, 'include_latest_season' => true])
        ->assertDispatched('aio-selection-cleared');

    Bus::assertDispatched(AddAioStreamsItemsToLibrary::class, fn (AddAioStreamsItemsToLibrary $job) => $job->integrationId === $this->integration->id
        && $job->items === [['type' => 'movie', 'id' => 'tt1'], ['type' => 'series', 'id' => 'tt2']]
        && $job->profileId === $profile->id
        && $job->includeLatestSeason);
});

it('hides the bulk add action in guest mode', function () {
    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id, 'guestMode' => true])
        ->assertActionHidden('bulkAddToLibrary');
});

it('adds movies and series in bulk with the latest aired season', function () {
    Bus::fake([ResolveAioStreamsChannel::class, ResolveAioStreamsEpisode::class]);
    Notification::fake();
    $profile = bulkAddProfile($this->user);

    (new AddAioStreamsItemsToLibrary($this->integration->id, [
        ['type' => 'movie', 'id' => 'tt1'],
        ['type' => 'series', 'id' => 'tt2'],
        ['type' => 'movie', 'id' => 'missing'],
    ], $profile->id, includeLatestSeason: true))->handle(app(AioStreamsLibraryService::class));

    $series = Series::where('aio_item_id', 'tt2')->firstOrFail();

    expect(Channel::where('aio_item_id', 'tt1')->value('aio_match_profile_id'))->toBe($profile->id)
        ->and($series->aio_match_profile_id)->toBe($profile->id)
        // Season 3 hasn't started airing, so season 2 is the latest; its unaired
        // episode is added as scheduled.
        ->and(Episode::where('series_id', $series->id)->orderBy('episode_num')->pluck('aio_resolution_status', 'aio_item_id')->all())
        ->toBe(['tt2:2:1' => 'pending', 'tt2:2:2' => 'scheduled']);

    Bus::assertDispatchedTimes(ResolveAioStreamsEpisode::class, 1);
    Notification::assertSentTo(
        $this->user,
        DatabaseNotification::class,
        fn ($notification) => $notification->toArray()['body'] === '2 titles added | 1 could not be loaded from AIOStreams',
    );
});

it('adds only the series row in bulk when the latest season is not requested', function () {
    (new AddAioStreamsItemsToLibrary($this->integration->id, [
        ['type' => 'series', 'id' => 'tt2'],
    ]))->handle(app(AioStreamsLibraryService::class));

    expect(Series::where('aio_item_id', 'tt2')->exists())->toBeTrue()
        ->and(Episode::count())->toBe(0);
});

it('skips titles already in the library and ignores another user\'s profile', function () {
    Bus::fake([ResolveAioStreamsChannel::class]);
    $otherProfile = bulkAddProfile(User::factory()->create(), 'Not mine');

    $job = new AddAioStreamsItemsToLibrary($this->integration->id, [['type' => 'movie', 'id' => 'tt1']], $otherProfile->id);
    $job->handle(app(AioStreamsLibraryService::class));
    $job->handle(app(AioStreamsLibraryService::class));

    expect(Channel::where('aio_item_id', 'tt1')->count())->toBe(1)
        ->and(Channel::where('aio_item_id', 'tt1')->value('aio_match_profile_id'))->toBeNull();
});

it('opens other addon types with their own type and offers no selection for them', function () {
    $html = view('livewire.partials.aiostreams-card', ['item' => ['id' => 'tv1', 'type' => 'tv', 'name' => 'A Channel']])->render();

    expect($html)
        ->toContain("openAioDetail', JSON.parse('{\\u0022type\\u0022:\\u0022tv\\u0022")
        ->not->toContain('aioSelection.toggle');

    $movieHtml = view('livewire.partials.aiostreams-card', ['item' => ['id' => 'tt1', 'type' => 'movie', 'name' => 'A Movie']])->render();

    expect($movieHtml)->toContain('aioSelection.toggle');
});

it('refuses a latest-season bulk add over the series limit', function () {
    Bus::fake([AddAioStreamsItemsToLibrary::class]);

    $items = collect(range(1, AddAioStreamsItemsToLibrary::MAX_SERIES_WITH_LATEST_SEASON + 1))
        ->map(fn (int $i): array => ['type' => 'series', 'id' => "tt{$i}0"])
        ->all();

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->callAction(TestAction::make('bulkAddToLibrary')->arguments(['items' => $items]), ['include_latest_season' => true])
        ->assertNotified(__('Too many series to include their latest season'));

    Bus::assertNotDispatched(AddAioStreamsItemsToLibrary::class);
});

it('falls back to the video poster when a bulk-added episode has no thumbnail', function () {
    Bus::fake([ResolveAioStreamsEpisode::class]);

    $series = app(AioStreamsLibraryService::class)->findOrCreateSeries($this->integration, 'tt2', ['name' => 'Bulk Series']);
    $episode = app(AioStreamsLibraryService::class)->createEpisode($series, 1, 1, [
        'season' => 1, 'episode' => 1, 'released' => '2020-01-01', 'poster' => 'https://x/still.jpg',
    ]);

    expect($episode->cover)->toBe('https://x/still.jpg');
});

it('notifies the user when the bulk add job fails', function () {
    Notification::fake();

    (new AddAioStreamsItemsToLibrary($this->integration->id, [['type' => 'movie', 'id' => 'tt1']]))->failed(new RuntimeException('timeout'));

    Notification::assertSentTo($this->user, DatabaseNotification::class);
});
