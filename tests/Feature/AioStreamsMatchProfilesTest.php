<?php

use App\Filament\Clusters\MediaServers\MediaServersCluster;
use App\Filament\Resources\AioStreamsMatchProfiles\AioStreamsMatchProfileResource;
use App\Filament\Resources\AioStreamsMatchProfiles\Pages\CreateAioStreamsMatchProfile;
use App\Filament\Resources\AioStreamsMatchProfiles\Pages\EditAioStreamsMatchProfile;
use App\Filament\Resources\AioStreamsMatchProfiles\Pages\ListAioStreamsMatchProfiles;
use App\Filament\Resources\MediaServerIntegrations\Pages\EditMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\AioStreamsMoviesRelationManager;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\AioStreamsSeriesRelationManager;
use App\Jobs\ResolveAioStreamsChannel;
use App\Jobs\ResolveAioStreamsEpisode;
use App\Models\AioStreamsMatchProfile;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\AioStreamsCandidateService;
use App\Services\AioStreamsQualityParser;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);

    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/abc/manifest.json',
        'playlist_id' => $this->playlist->id,
    ]);
    $this->channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'is_vod' => true,
        'title' => 'The Odyssey',
        'aio_integration_id' => $this->integration->id,
        'aio_item_id' => 'tt1',
        'aio_type' => 'movie',
        'aio_resolution_status' => 'pending',
    ]);

    // An English 4K remux, a French 1080p release and a Russian voice-over 4K release.
    $this->streams = [
        ['name' => '4K', 'url' => 'https://debrid.test/en-4k', 'behaviorHints' => ['filename' => 'The.Odyssey.2026.2160p.UHD.Remux.HEVC-GRP.mkv', 'videoSize' => 80 * 1_073_741_824]],
        ['name' => '1080p', 'url' => 'https://debrid.test/fr-1080', 'behaviorHints' => ['filename' => 'The.Odyssey.2026.MULTi.VFQ.1080p.WEB-DL.mkv', 'videoSize' => 4 * 1_073_741_824]],
        ['name' => '4K', 'url' => 'https://debrid.test/ru-4k', 'behaviorHints' => ['filename' => 'Одиссея (2026) 2160p BDRemux | D, P, A', 'videoSize' => 90 * 1_073_741_824]],
    ];
    Http::fake(['aiostreams.test/abc/stream/*' => Http::response(['streams' => $this->streams])]);
});

function aioMatchProfile(User $user, array $rules, string $name = 'Profile'): AioStreamsMatchProfile
{
    return AioStreamsMatchProfile::create(['user_id' => $user->id, 'name' => $name, 'rules' => $rules]);
}

function resolveAioMatchChannel(Channel $channel): Channel
{
    (new ResolveAioStreamsChannel($channel->id))->handle(new AioStreamsQualityParser);

    return $channel->fresh();
}

it('uses the built-in ranking when no profile applies', function () {
    // Largest 2160p first - the voice-over release, exactly the problem profiles solve.
    expect(resolveAioMatchChannel($this->channel)->movie_data['aiostreams']['resolved_url'])->toBe('https://debrid.test/ru-4k');
});

it('applies the integration movie default profile', function () {
    $profile = aioMatchProfile($this->user, ['languages' => ['en', 'original'], 'require_language' => true]);
    $this->integration->update(['aiostreams_movie_profile_id' => $profile->id]);

    $channel = resolveAioMatchChannel($this->channel);

    expect($channel->movie_data['aiostreams']['resolved_url'])->toBe('https://debrid.test/en-4k')
        ->and(ChannelFailover::where('channel_id', $channel->id)->count())->toBe(1);
});

it('lets a title override the integration default', function () {
    $english = aioMatchProfile($this->user, ['languages' => ['en', 'original'], 'require_language' => true]);
    $french = aioMatchProfile($this->user, ['languages' => ['fr'], 'require_language' => true]);
    $this->integration->update(['aiostreams_movie_profile_id' => $english->id]);
    $this->channel->update(['aio_match_profile_id' => $french->id]);

    expect(resolveAioMatchChannel($this->channel)->movie_data['aiostreams']['resolved_url'])->toBe('https://debrid.test/fr-1080');
});

it('resolves an episode with its series override, then the integration series default', function () {
    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
    ]);
    $episode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt2:1:1',
        'aio_resolution_status' => 'pending',
    ]);
    $seriesDefault = aioMatchProfile($this->user, ['resolutions' => [1080]]);
    $override = aioMatchProfile($this->user, ['languages' => ['en', 'original'], 'require_language' => true]);
    $this->integration->update(['aiostreams_series_profile_id' => $seriesDefault->id]);

    $service = app(AioStreamsCandidateService::class);
    expect($service->profileFor($episode->fresh())?->id)->toBe($seriesDefault->id);

    $series->update(['aio_match_profile_id' => $override->id]);
    (new ResolveAioStreamsEpisode($episode->id))->handle(new AioStreamsQualityParser);

    expect($service->profileFor($episode->fresh())?->id)->toBe($override->id)
        ->and($episode->fresh()->info['aiostreams']['resolved_url'])->toBe('https://debrid.test/en-4k');
});

it('falls back to the best available stream when nothing matches, by default', function () {
    $profile = aioMatchProfile($this->user, ['languages' => ['de'], 'require_language' => true]);
    $this->channel->update(['aio_match_profile_id' => $profile->id]);

    $channel = resolveAioMatchChannel($this->channel);

    expect($channel->aio_resolution_status)->not->toBe('failed')
        ->and($channel->movie_data['aiostreams']['resolved_url'])->not->toBeNull();
});

it('leaves the title unresolved when the profile says so', function () {
    $profile = aioMatchProfile($this->user, ['languages' => ['de'], 'require_language' => true, 'when_no_match' => 'leave_unresolved']);
    $this->channel->update(['aio_match_profile_id' => $profile->id]);

    $channel = resolveAioMatchChannel($this->channel);

    expect($channel->aio_resolution_status)->toBe('failed')
        ->and($channel->movie_data['aiostreams']['resolved_url'] ?? null)->toBeNull();
});

it('keeps the number of streams the profile asks for', function () {
    $profile = aioMatchProfile($this->user, ['max_candidates' => 1]);
    $this->channel->update(['aio_match_profile_id' => $profile->id]);

    $channel = resolveAioMatchChannel($this->channel);

    expect(ChannelFailover::where('channel_id', $channel->id)->count())->toBe(0)
        ->and($channel->aio_resolution_status)->toBe('resolved');
});

it('keeps a manual pick even when the profile would exclude it', function () {
    $profile = aioMatchProfile($this->user, ['languages' => ['en', 'original'], 'require_language' => true]);
    $this->channel->update([
        'aio_match_profile_id' => $profile->id,
        'movie_data' => ['aiostreams' => ['manual_keys' => [AioStreamsCandidateService::streamKey($this->streams[2])]]],
    ]);

    expect(resolveAioMatchChannel($this->channel)->movie_data['aiostreams']['resolved_url'])->toBe('https://debrid.test/ru-4k');
});

it('ignores another user\'s profile id and clears overrides when a profile is deleted', function () {
    $foreign = aioMatchProfile(User::factory()->create(), ['languages' => ['fr'], 'require_language' => true]);
    $this->channel->update(['aio_match_profile_id' => $foreign->id]);

    expect(app(AioStreamsCandidateService::class)->profileFor($this->channel->fresh()))->toBeNull();

    $own = aioMatchProfile($this->user, ['resolutions' => [1080]]);
    $this->channel->update(['aio_match_profile_id' => $own->id]);
    $own->delete();

    expect($this->channel->fresh()->aio_match_profile_id)->toBeNull();
});

it('lists only the current user\'s profiles', function () {
    $mine = aioMatchProfile($this->user, [], 'Mine');
    $theirs = aioMatchProfile(User::factory()->create(), [], 'Theirs');

    Livewire::test(ListAioStreamsMatchProfiles::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('tests the unsaved rules against a library title without changing it', function () {
    $profile = aioMatchProfile($this->user, []);

    $component = Livewire::test(EditAioStreamsMatchProfile::class, ['record' => $profile->id])
        ->fillForm(['rules' => ['languages' => ['en', 'original'], 'require_language' => true]])
        ->mountAction('testProfile')
        ->fillForm(['item' => "channel:{$this->channel->id}"])
        ->goToNextWizardStep();

    $outcomes = collect($component->instance()->mountedActions[0]['data']['results'])->pluck('outcome')->all();

    expect($outcomes)->toBe(['Primary', 'Failover 1', 'Excluded: Language'])
        ->and($profile->fresh()->rules)->toBe([])
        ->and($this->channel->fresh()->movie_data)->toBeNull();
});

it('saves a movie profile override from the rescan action', function () {
    Bus::fake();
    $profile = aioMatchProfile($this->user, ['resolutions' => [1080]]);

    Livewire::test(AioStreamsMoviesRelationManager::class, [
        'ownerRecord' => $this->integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('rescan')->table($this->channel), ['mode' => 'automatic', 'profile_id' => $profile->id]);

    expect($this->channel->fresh()->aio_match_profile_id)->toBe($profile->id);
    Bus::assertDispatched(ResolveAioStreamsChannel::class);
});

it('saves a series profile override from the series rescan action', function () {
    Bus::fake();
    $profile = aioMatchProfile($this->user, ['resolutions' => [1080]]);
    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
    ]);

    Livewire::test(AioStreamsSeriesRelationManager::class, [
        'ownerRecord' => $this->integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('rescan')->table($series), ['profile_id' => $profile->id]);

    expect($series->fresh()->aio_match_profile_id)->toBe($profile->id);
});

it('saves the integration default profiles', function () {
    $movies = aioMatchProfile($this->user, [], 'Movies');
    $series = aioMatchProfile($this->user, [], 'Series');

    Livewire::test(EditMediaServerIntegration::class, ['record' => $this->integration->id])
        ->fillForm(['aiostreams_movie_profile_id' => $movies->id, 'aiostreams_series_profile_id' => $series->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->integration->fresh())
        ->aiostreams_movie_profile_id->toBe($movies->id)
        ->aiostreams_series_profile_id->toBe($series->id);
});

it('creates a profile from the form in the shape the parser reads', function () {
    Livewire::test(CreateAioStreamsMatchProfile::class)
        ->fillForm([
            'name' => '4K English',
            // A simple repeater's form state is keyed items wrapping the one field.
            'rules.resolutions' => ['a' => ['resolution' => '2160'], 'b' => ['resolution' => '1080']],
            'rules.languages' => ['a' => ['language' => 'en'], 'b' => ['language' => 'original']],
            'rules.require_language' => true,
            'rules.excluded_keywords' => ['Dubbed'],
            'rules.max_size_gb' => '60',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $profile = AioStreamsMatchProfile::where('name', '4K English')->firstOrFail();
    $rules = (new AioStreamsQualityParser)->normalizeRules($profile->getRules());

    expect($profile->user_id)->toBe($this->user->id)
        ->and($profile->rules['resolutions'])->toBe([2160, 1080])
        ->and($rules['resolutions'])->toBe([2160, 1080])
        ->and($rules['languages'])->toBe(['en', 'original'])
        ->and($rules['require_language'])->toBeTrue()
        ->and($rules['excluded_keywords'])->toBe(['Dubbed'])
        ->and($rules['max_size_bytes'])->toBe(60 * 1_073_741_824);
});

it('lists the profiles in the Media Servers navigation only once an AIOStreams integration exists', function () {
    expect(AioStreamsMatchProfileResource::shouldRegisterNavigation())->toBeTrue()
        ->and(MediaServersCluster::getClusteredComponents())
        ->toContain(AioStreamsMatchProfileResource::class);

    $this->actingAs(User::factory()->create(['permissions' => ['use_integrations']]));

    expect(AioStreamsMatchProfileResource::shouldRegisterNavigation())->toBeFalse();
});
