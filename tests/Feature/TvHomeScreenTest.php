<?php

use App\Enums\DefaultAuthMode;
use App\Events\TvClientConfigUpdatedEvent;
use App\Filament\Clusters\Devices\DevicesCluster;
use App\Filament\Resources\TvHomeScreens\Pages\ListTvHomeScreens;
use App\Filament\Resources\TvHomeScreens\TvHomeScreenResource;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\TvClientProfile;
use App\Models\TvDevice;
use App\Models\TvHomeScreen;
use App\Models\User;
use App\Services\TvClientConfigService;
use App\Services\TvHomeScreenCategories;
use App\Support\TvHomeScreenLayout;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->admin()->create(['name' => 'owner']);
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'default_auth_mode' => DefaultAuthMode::Uuid,
    ]);

    $this->auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'username' => 'home-user',
        'password' => 'home-pass',
    ]);
    $this->auth->assignTo($this->playlist);
});

function homeScreenConfigUrl(string $username, string $password, array $query = []): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => $username,
        'password' => $password,
        ...$query,
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function sectionsWith(string $sectionKey, array $rows): array
{
    return array_map(function (array $section) use ($sectionKey, $rows): array {
        if ($section['section'] === $sectionKey) {
            $section['rows'] = $rows;
        }

        return $section;
    }, TvHomeScreenLayout::defaultSections());
}

function sectionByKey(array $sections, string $key): array
{
    return collect($sections)->firstWhere('section', $key);
}

it('normalizes a layout: every section once, one built-in row each, valid custom rows only', function () {
    $sections = TvHomeScreenLayout::normalize([
        ['section' => 'series', 'nav' => false, 'home' => '1', 'rows' => [
            ['id' => 'xmas', 'kind' => 'category', 'title' => 'Christmas', 'params' => ['category_id' => 812], 'window' => ['from' => '12-01', 'to' => '12-31']],
            ['kind' => 'default'],
            ['kind' => 'default'],
            ['kind' => 'sports_fixtures', 'title' => 'Wrong section', 'params' => ['leagues' => ['football/nfl']]],
            ['kind' => 'category', 'title' => 'No category', 'params' => []],
            ['kind' => 'category', 'title' => 'Bad window', 'params' => ['category_id' => '9'], 'window' => ['from' => '13-01', 'to' => '12-31']],
        ]],
        ['section' => 'series', 'rows' => []],
        ['section' => 'not_a_section'],
        ['section' => 'sports', 'rows' => [
            ['kind' => 'sports_fixtures', 'title' => 'Game Day', 'params' => ['leagues' => ['football/nfl', 'football/nfl'], 'range' => 'forever', 'limit' => 500]],
        ]],
    ]);

    expect(array_column($sections, 'section'))->toBe(['series', 'sports', 'live_tv', 'movies', 'aiostreams', 'dvr', 'requests']);

    $series = sectionByKey($sections, 'series');
    expect($series['nav'])->toBeFalse()
        ->and($series['home'])->toBeTrue()
        ->and($series['rows'])->toHaveCount(3)
        ->and($series['rows'][0])->toBe([
            'id' => 'xmas',
            'kind' => 'category',
            'title' => 'Christmas',
            'params' => ['category_id' => '812'],
            'window' => ['from' => '12-01', 'to' => '12-31'],
        ])
        ->and($series['rows'][1])->toBe(['id' => 'default', 'kind' => 'default'])
        ->and($series['rows'][2])->not->toHaveKey('window');

    $sports = sectionByKey($sections, 'sports');
    expect($sports['rows'][0])->toBe(['id' => 'default', 'kind' => 'default'])
        ->and($sports['rows'][1]['params'])->toBe(['leagues' => ['football/nfl'], 'range' => 'today', 'limit' => 30])
        ->and($sports['rows'][1]['id'])->toBeString()->not->toBe('');

    expect(sectionByKey($sections, 'aiostreams')['home'])->toBeFalse()
        ->and(sectionByKey($sections, 'requests')['home'])->toBeFalse()
        ->and(sectionByKey($sections, 'dvr')['home'])->toBeTrue();
});

it('serves the playlist\'s Home screen to its Playlist Auths, only with the row kinds the app can draw', function () {
    TvHomeScreen::factory()->for($this->user)->enforced()->create([
        'playlist_id' => $this->playlist->id,
        'sections' => sectionsWith('series', [
            ['kind' => 'default'],
            ['id' => 'xmas', 'kind' => 'category', 'title' => 'Christmas', 'params' => ['category_id' => '812']],
        ]),
    ]);

    $handshake = $this->getJson(homeScreenConfigUrl('home-user', 'home-pass'))->assertOk();

    $config = $this->getJson(homeScreenConfigUrl('home-user', 'home-pass', [
        'action' => 'get_client_config',
        'row_kinds' => 'default,category,sports_fixtures',
    ]))->assertOk();

    expect($config->json('home_screen.mode'))->toBe('enforced')
        ->and(sectionByKey($config->json('home_screen.sections'), 'series')['rows'])->toHaveCount(2)
        ->and($config->json('revision'))->toBe($handshake->json('m3u_editor.client_config.revision'));

    $baseline = $this->getJson(homeScreenConfigUrl('home-user', 'home-pass', ['action' => 'get_client_config']))->assertOk();

    expect(sectionByKey($baseline->json('home_screen.sections'), 'series')['rows'])
        ->toBe([['id' => 'default', 'kind' => 'default']])
        ->and($baseline->json('revision'))->toBe($config->json('revision'));

    // The owner's own login to the playlist gets it too.
    $this->getJson(homeScreenConfigUrl('owner', $this->playlist->uuid, ['action' => 'get_client_config']))
        ->assertOk()
        ->assertJsonPath('home_screen.mode', 'enforced');
});

it('gives logins to a playlist without a Home screen none, and leaves a profile-only revision unchanged', function () {
    $profile = TvClientProfile::factory()->for($this->user)->asDefault()->create([
        'settings' => ['deinterlace' => ['mode' => 'enforced', 'value' => true]],
    ]);

    $config = $this->getJson(homeScreenConfigUrl('home-user', 'home-pass', ['action' => 'get_client_config']))->assertOk();

    expect($config->json('home_screen'))->toBeNull()
        ->and($config->json('revision'))->toBe(app(TvClientConfigService::class)->revision($profile->managedSettings()));

    // Another playlist's Home screen doesn't apply.
    TvHomeScreen::factory()->for($this->user)->create();

    $this->getJson(homeScreenConfigUrl('home-user', 'home-pass', ['action' => 'get_client_config']))
        ->assertOk()
        ->assertJsonPath('home_screen', null);
});

it('changes the revision when the Home screen changes', function () {
    $homeScreen = TvHomeScreen::factory()->for($this->user)->create(['playlist_id' => $this->playlist->id]);
    $before = $this->getJson(homeScreenConfigUrl('home-user', 'home-pass'))->json('m3u_editor.client_config.revision');

    $homeScreen->update(['sections' => sectionsWith('movies', [
        ['kind' => 'default'],
        ['kind' => 'category', 'title' => 'Kids', 'params' => ['category_id' => '7']],
    ])]);

    expect($this->getJson(homeScreenConfigUrl('home-user', 'home-pass'))->json('m3u_editor.client_config.revision'))
        ->not->toBe($before);
});

it('notifies every device on the old and new playlist when a Home screen is saved or moved', function () {
    Event::fake([TvClientConfigUpdatedEvent::class]);

    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    TvDevice::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth->id,
    ]);
    TvDevice::factory()->create([
        'notifiable_type' => $otherPlaylist->getMorphClass(),
        'notifiable_id' => $otherPlaylist->id,
        'playlist_auth_id' => null,
    ]);

    $homeScreen = TvHomeScreen::factory()->for($this->user)->create(['playlist_id' => $this->playlist->id]);
    $homeScreen->update(['target_key' => "playlist_id:{$otherPlaylist->id}"]);

    expect($homeScreen->refresh()->playlist_id)->toBe($otherPlaylist->id);

    $type = $this->playlist->getMorphClass();

    Event::assertDispatched(TvClientConfigUpdatedEvent::class, function (TvClientConfigUpdatedEvent $event) use ($type, $otherPlaylist): bool {
        $channels = $event->channelNames;
        sort($channels);

        $expected = [
            "tv.{$type}.{$this->playlist->uuid}.{$this->auth->id}",
            "tv.{$type}.{$otherPlaylist->uuid}",
            "tv.{$type}-admin.{$otherPlaylist->uuid}",
        ];
        sort($expected);

        return $channels === $expected;
    });
});

it('finds the Home screen of every playlist type a login can resolve to, and goes when the playlist does', function () {
    $custom = CustomPlaylist::factory()->for($this->user)->create();
    $homeScreen = TvHomeScreen::factory()->for($this->user)->create(['target_key' => "custom_playlist_id:{$custom->id}"]);

    expect(TvHomeScreen::forPlaylist($custom)?->is($homeScreen))->toBeTrue()
        ->and(TvHomeScreen::forPlaylist($this->playlist))->toBeNull()
        ->and($homeScreen->playlist_id)->toBeNull()
        ->and($homeScreen->target()?->is($custom))->toBeTrue();

    $custom->delete();

    expect(TvHomeScreen::count())->toBe(0);
});

it('lists the categories the playlist\'s app sees', function () {
    $news = Group::factory()->for($this->user)->create(['playlist_id' => $this->playlist->id, 'name' => 'News']);
    $empty = Group::factory()->for($this->user)->create(['playlist_id' => $this->playlist->id, 'name' => 'Empty']);
    Channel::factory()->for($this->user)->create([
        'playlist_id' => $this->playlist->id,
        'group_id' => $news->id,
        'enabled' => true,
        'is_vod' => false,
    ]);

    $categories = app(TvHomeScreenCategories::class)->for($this->playlist, 'live');

    expect($categories)->toHaveKey((string) $news->id)
        ->and($categories[(string) $news->id])->toBe('News')
        ->and($categories)->not->toHaveKey((string) $empty->id)
        ->and(app(TvHomeScreenCategories::class)->for(null, 'live'))->toBe([]);
});

it('creates a Home screen from the panel with a category row', function () {
    $news = Group::factory()->for($this->user)->create(['playlist_id' => $this->playlist->id, 'name' => 'News']);
    Channel::factory()->for($this->user)->create([
        'playlist_id' => $this->playlist->id,
        'group_id' => $news->id,
        'enabled' => true,
        'is_vod' => false,
    ]);
    $this->actingAs($this->user);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ListTvHomeScreens::class)
        ->callAction(CreateAction::class, [
            'target_key' => "playlist_id:{$this->playlist->id}",
            'name' => 'Living room',
            'mode' => 'default',
            'sections' => sectionsWith('live_tv', [
                ['id' => 'default', 'kind' => 'default'],
                ['id' => 'news-row', 'kind' => 'category', 'title' => 'News', 'params' => ['category_id' => (string) $news->id], 'window' => ['from' => null, 'to' => null]],
            ]),
        ])
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $homeScreen = TvHomeScreen::firstWhere('name', 'Living room');

    expect($homeScreen->user_id)->toBe($this->user->id)
        ->and($homeScreen->playlist_id)->toBe($this->playlist->id)
        ->and(sectionByKey($homeScreen->sections, 'live_tv')['rows'][1])->toBe([
            'id' => 'news-row',
            'kind' => 'category',
            'title' => 'News',
            'params' => ['category_id' => (string) $news->id],
        ]);
});

it('duplicates to another playlist, leaving out the category rows it doesn\'t have', function () {
    $this->actingAs($this->user);
    $otherPlaylist = Playlist::factory()->for($this->user)->create(['name' => 'Bedroom']);
    $shared = Group::factory()->for($this->user)->create(['playlist_id' => $otherPlaylist->id, 'name' => 'Shared']);
    Channel::factory()->for($this->user)->create([
        'playlist_id' => $otherPlaylist->id,
        'group_id' => $shared->id,
        'enabled' => true,
        'is_vod' => false,
    ]);

    $homeScreen = TvHomeScreen::factory()->for($this->user)->create([
        'playlist_id' => $this->playlist->id,
        'sections' => sectionsWith('live_tv', [
            ['kind' => 'default'],
            ['id' => 'kept', 'kind' => 'category', 'title' => 'Shared', 'params' => ['category_id' => (string) $shared->id]],
            ['id' => 'gone', 'kind' => 'category', 'title' => 'Christmas', 'params' => ['category_id' => '812']],
        ]),
    ]);

    Livewire::test(ListTvHomeScreens::class)
        ->callAction(TestAction::make('duplicate')->table($homeScreen), ['target_key' => "playlist_id:{$otherPlaylist->id}"])
        ->assertNotified();

    $copy = TvHomeScreen::firstWhere('playlist_id', $otherPlaylist->id);

    expect(array_column(sectionByKey($copy->sections, 'live_tv')['rows'], 'id'))->toBe(['default', 'kept']);
});

it('only offers playlists without a Home screen and sits in the Devices cluster', function () {
    $this->actingAs($this->user);
    $free = Playlist::factory()->for($this->user)->create(['name' => 'Free']);
    $custom = CustomPlaylist::factory()->for($this->user)->create(['name' => 'Kids']);
    TvHomeScreen::factory()->for($this->user)->create(['playlist_id' => $this->playlist->id]);

    $options = collect(TvHomeScreenResource::availablePlaylistOptions(null))->collapse();

    expect($options)->toHaveKey("playlist_id:{$free->id}")
        ->toHaveKey("custom_playlist_id:{$custom->id}")
        ->not->toHaveKey("playlist_id:{$this->playlist->id}")
        ->and(DevicesCluster::getClusteredComponents())->toContain(TvHomeScreenResource::class)
        ->and(TvHomeScreenResource::canAccess())->toBeTrue();
});

it('rejects a category that isn\'t in the playlist', function () {
    $this->actingAs($this->user);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ListTvHomeScreens::class)
        ->callAction(CreateAction::class, [
            'target_key' => "playlist_id:{$this->playlist->id}",
            'name' => 'Living room',
            'mode' => 'default',
            'sections' => sectionsWith('live_tv', [
                ['id' => 'default', 'kind' => 'default'],
                ['id' => 'stray', 'kind' => 'category', 'title' => 'Stray', 'params' => ['category_id' => '999999'], 'window' => ['from' => null, 'to' => null]],
            ]),
        ])
        ->assertHasFormErrors(['sections.0.rows.1.params.category_id'])
        ->assertHasNoFormErrors(['sections.0.rows.1.title', 'name', 'target_key']);

    $undoRepeaterFake();

    expect(TvHomeScreen::count())->toBe(0);
});

it('gives every row its own key on a new Home screen, so rows can be dragged', function () {
    $this->actingAs($this->user);

    $livewire = Livewire::test(ListTvHomeScreens::class)->mountAction(CreateAction::class);

    foreach ($livewire->instance()->mountedActions[0]['data']['sections'] as $section) {
        foreach (array_keys($section['rows']) as $rowKey) {
            expect($rowKey)->toBeString()->and(Str::isUuid($rowKey))->toBeTrue();
        }
    }
});

it('picks the show dates as days of the year and stores them as MM-DD', function () {
    $this->travelTo('2027-06-01');
    $this->actingAs($this->user);
    $holiday = Group::factory()->for($this->user)->create(['playlist_id' => $this->playlist->id, 'name' => 'Holiday Specials']);
    Channel::factory()->for($this->user)->create([
        'playlist_id' => $this->playlist->id,
        'group_id' => $holiday->id,
        'enabled' => true,
        'is_vod' => false,
    ]);
    $homeScreen = TvHomeScreen::factory()->for($this->user)->create([
        'playlist_id' => $this->playlist->id,
        'sections' => sectionsWith('live_tv', [
            ['kind' => 'default'],
            ['id' => 'holiday', 'kind' => 'category', 'title' => 'Holiday', 'params' => ['category_id' => (string) $holiday->id], 'window' => ['from' => '12-15', 'to' => '01-05']],
        ]),
    ]);

    $livewire = Livewire::test(ListTvHomeScreens::class)->mountAction(TestAction::make('edit')->table($homeScreen));

    // The row's category picker lists the playlist's categories.
    $categoryPicker = collect($livewire->instance()->getSchema('mountedActionSchema0')->getFlatFields(withHidden: true))
        ->first(fn ($field): bool => str_ends_with($field->getStatePath(), '.params.category_id') && $field->getOptions() !== []);

    expect($categoryPicker?->getOptions())->toBe([(string) $holiday->id => 'Holiday Specials']);

    $liveTv = collect($livewire->instance()->mountedActions[0]['data']['sections'])->firstWhere('section', 'live_tv');

    expect(array_values($liveTv['rows'])[1]['window'])->toBe(['from' => '2027-12-15 00:00:00', 'to' => '2027-01-05 00:00:00']);

    $livewire->callMountedAction()->assertHasNoFormErrors();

    expect(sectionByKey($homeScreen->refresh()->sections, 'live_tv')['rows'][1]['window'])
        ->toBe(['from' => '12-15', 'to' => '01-05']);
});

it('keeps the playlist on edit', function () {
    $this->actingAs($this->user);
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $homeScreen = TvHomeScreen::factory()->for($this->user)->create(['playlist_id' => $this->playlist->id]);

    Livewire::test(ListTvHomeScreens::class)
        ->mountAction(TestAction::make('edit')->table($homeScreen))
        ->fillForm(['target_key' => "playlist_id:{$otherPlaylist->id}", 'name' => 'Renamed'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($homeScreen->refresh())
        ->name->toBe('Renamed')
        ->playlist_id->toBe($this->playlist->id);
});

it('clears picked categories when the playlist changes on create', function () {
    $this->actingAs($this->user);
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $undoRepeaterFake = Repeater::fake();

    $livewire = Livewire::test(ListTvHomeScreens::class)
        ->mountAction(CreateAction::class)
        ->fillForm([
            'target_key' => "playlist_id:{$this->playlist->id}",
            'sections' => sectionsWith('live_tv', [
                ['id' => 'default', 'kind' => 'default'],
                ['id' => 'news-row', 'kind' => 'category', 'title' => 'News', 'params' => ['category_id' => '42'], 'window' => ['from' => null, 'to' => null]],
            ]),
        ])
        ->fillForm(['target_key' => "playlist_id:{$otherPlaylist->id}"]);

    $undoRepeaterFake();

    $row = sectionByKey($livewire->instance()->mountedActions[0]['data']['sections'], 'live_tv')['rows'][1];

    expect($row['params']['category_id'])->toBeNull()
        ->and($row['title'])->toBe('News');
});
