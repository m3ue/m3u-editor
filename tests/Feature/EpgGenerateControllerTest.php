<?php

use App\Enums\EpgSourceType;
use App\Events\CustomPlaylistCreated;
use App\Events\EpgCreated;
use App\Events\EpgDeleted;
use App\Events\EpgUpdated;
use App\Events\MergedPlaylistCreated;
use App\Events\PlaylistCreated;
use App\Events\PlaylistDeleted;
use App\Events\PlaylistUpdated;
use App\Facades\ProxyFacade;
use App\Http\Controllers\LogoProxyController;
use App\Models\AedProfile;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\User;
use App\Services\EpgCacheService;
use Carbon\Carbon;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([
        EpgCreated::class,
        EpgDeleted::class,
        EpgUpdated::class,
        PlaylistCreated::class,
        PlaylistDeleted::class,
        PlaylistUpdated::class,
        CustomPlaylistCreated::class,
        MergedPlaylistCreated::class,
    ]);

    // Clean up any leftover playlist EPG cache files from previous runs
    Storage::disk('local')->deleteDirectory('playlist-epg-files');
});

test('epg download does not crash when epg source file is missing', function () {
    $user = User::factory()->create();

    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
    ]);

    // Create an EPG with a URL but no cached data and no file on disk
    $epg = Epg::factory()->create([
        'user_id' => $user->id,
        'url' => 'http://example.com/epg.xml',
        'is_cached' => false,
    ]);

    $epgChannel = EpgChannel::factory()->create([
        'epg_id' => $epg->id,
        'channel_id' => 'test-channel-1',
        'user_id' => $user->id,
    ]);

    Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'channel' => 1,
    ]);

    // The EPG source file does not exist - should return valid XML without crashing
    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/gzip');

    $content = gzdecode($response->getContent());
    expect($content)->toContain('<?xml version="1.0"');
    expect($content)->toContain('</tv>');
});

test('epg download does not crash when epg source file is corrupted', function () {
    $user = User::factory()->create();

    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
    ]);

    $epg = Epg::factory()->create([
        'user_id' => $user->id,
        'url' => 'http://example.com/epg.xml',
        'is_cached' => false,
    ]);

    // Write a corrupted file at the expected path
    Storage::disk('local')->put($epg->file_path, 'not-valid-xml-or-gzip-data');

    $epgChannel = EpgChannel::factory()->create([
        'epg_id' => $epg->id,
        'channel_id' => 'test-channel-1',
        'user_id' => $user->id,
    ]);

    Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'channel' => 1,
    ]);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/gzip');

    $content = gzdecode($response->getContent());
    expect($content)->toContain('<?xml version="1.0"');
    expect($content)->toContain('</tv>');

    // Cleanup
    Storage::disk('local')->delete($epg->file_path);
});

test('xmlreader fallback preserves programme identities and artwork while omitting invalid identities', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
    ]);
    $epg = Epg::factory()->for($user)->create([
        'url' => 'https://example.com/fallback-identity.xml',
        'is_cached' => false,
    ]);
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => 'source.fallback.identity',
        'display_name' => 'Fallback Identity Channel',
        'lang' => 'en',
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'stream_id' => 'fallback-identity-channel',
        'title' => 'Fallback Identity Channel',
        'channel' => 1,
    ]);

    $start = now()->startOfDay()->addHour()->format('YmdHis O');
    $stop = now()->startOfDay()->addHours(2)->format('YmdHis O');
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <channel id="source.fallback.identity"><display-name>Fallback Identity Channel</display-name></channel>
  <programme start="{$start}" stop="{$stop}" channel="source.fallback.identity">
    <title>Fallback Identity Programme</title>
    <icon src="https://example.com/fallback-programme-artwork.jpg" />
    <episode-num system="xmltv_ns">1 . 4/10 .</episode-num>
    <episode-num system="provider-counter">0</episode-num>
    <episode-num system="dd_progid">EP012345670089</episode-num>
    <episode-num system="onscreen">S02E05</episode-num>
    <episode-num system="provider.example/id">series-0</episode-num>
    <episode-num>Unclassified 7</episode-num>
    <episode-num system="xmltv_ns">1..2..3</episode-num>
    <episode-num system="xmltv_ns">   </episode-num>
    <episode-num system="provider-counter">   </episode-num>
  </programme>
</tv>
XML;

    Storage::disk('local')->put($epg->file_path, gzencode($xml));

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $xpath = new DOMXPath($document);
    $episodeNumbers = [];
    foreach ($xpath->query('//programme[@channel="fallback-identity-channel"]/episode-num') as $episodeNumber) {
        $episodeNumbers[] = [
            'system' => $episodeNumber->hasAttribute('system') ? $episodeNumber->getAttribute('system') : null,
            'value' => $episodeNumber->textContent,
        ];
    }

    expect($episodeNumbers)->toBe([
        ['system' => 'xmltv_ns', 'value' => '1 . 4/10 .'],
        ['system' => 'provider-counter', 'value' => '0'],
        ['system' => 'dd_progid', 'value' => 'EP012345670089'],
        ['system' => 'onscreen', 'value' => 'S02E05'],
        ['system' => 'provider.example/id', 'value' => 'series-0'],
        ['system' => null, 'value' => 'Unclassified 7'],
    ])->and($xpath->query('//programme[@channel="fallback-identity-channel"]/icon[@src="https://example.com/fallback-programme-artwork.jpg"]'))->toHaveCount(1);
});

test('xmlreader fallback reads the local SchedulesDirect XMLTV file when cache data is unavailable', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
    ]);
    $epg = Epg::factory()->for($user)->create([
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'url' => null,
        'uploads' => null,
        'is_cached' => false,
    ]);
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => 'source.schedules-direct-fallback',
        'display_name' => 'Schedules Direct Fallback Channel',
        'lang' => 'en',
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'stream_id' => 'schedules-direct-fallback-channel',
        'title' => 'Schedules Direct Fallback Channel',
        'channel' => 1,
    ]);

    $start = now()->startOfDay()->addHour()->format('YmdHis O');
    $stop = now()->startOfDay()->addHours(2)->format('YmdHis O');
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <programme start="{$start}" stop="{$stop}" channel="source.schedules-direct-fallback">
    <title>Schedules Direct Fallback Programme</title>
  </programme>
</tv>
XML;

    Storage::disk('local')->put($epg->file_path, gzencode($xml));

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $xpath = new DOMXPath($document);

    expect($xpath->query('//programme[@channel="schedules-direct-fallback-channel"]/title[text()="Schedules Direct Fallback Programme"]'))->toHaveCount(1);
});

test('xmlreader fallback reports a missing local SchedulesDirect XMLTV file when cache data is unavailable', function () {
    NotificationFacade::fake();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
    ]);
    $epg = Epg::factory()->for($user)->create([
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'url' => null,
        'uploads' => null,
        'is_cached' => false,
    ]);
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => 'source.schedules-direct-missing-file',
        'display_name' => 'Schedules Direct Missing File Channel',
        'lang' => 'en',
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'stream_id' => 'schedules-direct-missing-file-channel',
        'title' => 'Schedules Direct Missing File Channel',
        'channel' => 1,
    ]);

    expect(Storage::disk('local')->missing($epg->file_path))->toBeTrue();

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    NotificationFacade::assertSentTo(
        $user,
        DatabaseNotification::class,
        fn (DatabaseNotification $notification): bool => $notification->data['status'] === 'danger'
            && $notification->data['title'] === "Error generating epg data for playlist \"{$playlist->name}\" using EPG \"{$epg->name}\""
            && $notification->data['body'] === 'SchedulesDirect EPG source file is missing from local storage. Please sync the EPG and try again.',
    );
    NotificationFacade::assertNotSentTo(
        $user,
        DatabaseNotification::class,
        fn (DatabaseNotification $notification): bool => $notification->data['body'] === 'Invalid EPG file. Unable to read or download an associated EPG file. Please check the URL or uploaded file and try again.',
    );
});

test('epg xml generation preserves albanian characters with explicit utf8 escaping', function () {
    $previousCharset = ini_get('default_charset');
    ini_set('default_charset', 'ISO-8859-1');

    try {
        $user = User::factory()->create();

        $playlist = Playlist::factory()->for($user)->create([
            'dummy_epg' => true,
            'dummy_epg_length' => 60,
        ]);

        Channel::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $user->id,
            'enabled' => true,
            'is_vod' => false,
            'title' => 'Çështje të Ëmbla & "Special"',
            'name' => 'RTK Çifteli',
            'stream_id' => 'rtk-cifteli',
            'channel' => 1,
        ]);

        $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/gzip');

        $content = gzdecode($response->getContent());

        expect($content)->toContain('<display-name>Çështje të Ëmbla &amp; &quot;Special&quot;</display-name>');
    } finally {
        ini_set('default_charset', $previousCharset ?: 'UTF-8');
    }
});

test('cached epg generation preserves ordered episode number identities from source xml', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
    ]);
    $epg = Epg::factory()->for($user)->create([
        'url' => 'https://example.com/identity.xml',
    ]);
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => 'source.identity',
        'display_name' => 'Identity Channel',
        'lang' => 'en',
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'stream_id' => 'identity-channel',
        'title' => 'Identity Channel',
        'channel' => 1,
    ]);

    $date = now()->format('Y-m-d');
    $start = now()->startOfDay()->addHour()->format('YmdHis O');
    $stop = now()->startOfDay()->addHours(2)->format('YmdHis O');
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <channel id="source.identity"><display-name>Identity Channel</display-name></channel>
  <programme start="{$start}" stop="{$stop}" channel="source.identity">
    <title>Identity Programme</title>
    <icon src="https://example.com/programme-artwork.jpg" />
    <episode-num system="xmltv_ns">1 . 4/10 .</episode-num>
    <episode-num system="dd_progid">EP012345670089</episode-num>
    <episode-num system="m3u-editor:content-id">gracenote:EP012345670089</episode-num>
    <episode-num system="m3u-editor:content-id">gracenote:EP012345670089</episode-num>
    <episode-num system="m3u-editor:series-id">gracenote:SH012345670000</episode-num>
    <episode-num system="onscreen">S02E05</episode-num>
    <episode-num system="provider.example/id">series-0</episode-num>
    <episode-num>Unclassified 7</episode-num>
    <episode-num system=" xmltv_ns ">0</episode-num>
    <episode-num system="xmltv_ns">0</episode-num>
    <episode-num system="xmltv_ns">1..2..3</episode-num>
    <episode-num system="onscreen">   </episode-num>
  </programme>
</tv>
XML;

    Storage::disk('local')->put($epg->file_path, gzencode($xml));

    expect(app(EpgCacheService::class)->cacheEpgData($epg))->toBeTrue();

    $cachedProgramme = app(EpgCacheService::class)
        ->getCachedProgrammes($epg, $date, ['source.identity'])['source.identity'][0];
    $expectedEpisodeNumbers = [
        ['system' => 'xmltv_ns', 'value' => '1 . 4/10 .'],
        ['system' => 'dd_progid', 'value' => 'EP012345670089'],
        ['system' => 'm3u-editor:content-id', 'value' => 'gracenote:EP012345670089'],
        ['system' => 'm3u-editor:content-id', 'value' => 'gracenote:EP012345670089'],
        ['system' => 'm3u-editor:series-id', 'value' => 'gracenote:SH012345670000'],
        ['system' => 'onscreen', 'value' => 'S02E05'],
        ['system' => 'provider.example/id', 'value' => 'series-0'],
        ['system' => null, 'value' => 'Unclassified 7'],
        ['system' => ' xmltv_ns ', 'value' => '0'],
    ];

    expect($cachedProgramme['episode_nums'])->toBe($expectedEpisodeNumbers)
        ->and($cachedProgramme['icon'])->toBe('https://example.com/programme-artwork.jpg');

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $episodeNumbers = [];
    foreach ($document->getElementsByTagName('episode-num') as $episodeNumber) {
        $episodeNumbers[] = [
            'system' => $episodeNumber->hasAttribute('system') ? $episodeNumber->getAttribute('system') : null,
            'value' => $episodeNumber->textContent,
        ];
    }

    $expectedGeneratedEpisodeNumbers = [
        ['system' => 'xmltv_ns', 'value' => '1 . 4/10 .'],
        ['system' => 'dd_progid', 'value' => 'EP012345670089'],
        ['system' => 'onscreen', 'value' => 'S02E05'],
        ['system' => 'provider.example/id', 'value' => 'series-0'],
        ['system' => null, 'value' => 'Unclassified 7'],
        ['system' => ' xmltv_ns ', 'value' => '0'],
        ['system' => 'm3u-editor:content-id', 'value' => 'gracenote:EP012345670089'],
        ['system' => 'm3u-editor:series-id', 'value' => 'gracenote:SH012345670000'],
    ];
    $xpath = new DOMXPath($document);

    expect($episodeNumbers)->toBe($expectedGeneratedEpisodeNumbers)
        ->and($xpath->query('//programme[@channel="identity-channel"]/icon[@src="https://example.com/programme-artwork.jpg"]'))->toHaveCount(1);
});

test('cached Schedules Direct programme artwork bypasses only the redundant logo proxy', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
        'enable_logo_proxy' => true,
    ]);
    $epg = Epg::factory()->for($user)->create([
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'is_cached' => true,
    ]);
    $untrustedSchedulesDirectEpg = Epg::factory()->for($user)->create([
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
    ]);
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => 'source.schedules-direct',
        'display_name' => 'Schedules Direct Channel',
        'lang' => 'en',
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'stream_id' => 'schedules-direct-channel',
        'title' => 'Schedules Direct Channel',
        'channel' => 1,
        'group_id' => null,
    ]);

    $baseUrl = rtrim(ProxyFacade::getBaseUrl(), '/');
    $schedulesDirectIcon = "{$baseUrl}/schedules-direct/{$epg->uuid}/image/icon-artwork";
    $schedulesDirectImages = [
        "{$baseUrl}/schedules-direct/{$epg->uuid}/image/poster-artwork",
        "{$baseUrl}/schedules-direct/{$epg->uuid}/image/backdrop-artwork",
    ];
    $dotSegmentIcon = "{$baseUrl}/schedules-direct/{$epg->uuid}/image/.";
    $dotDotSegmentImage = "{$baseUrl}/schedules-direct/{$epg->uuid}/image/..";
    $untrustedSchedulesDirectImage = "{$baseUrl}/schedules-direct/{$untrustedSchedulesDirectEpg->uuid}/image/untrusted-artwork";
    $queryIcon = "{$baseUrl}/schedules-direct/{$epg->uuid}/image/query-icon?untrusted=value";
    $queryImage = "{$baseUrl}/schedules-direct/{$epg->uuid}/image/query-image?untrusted=value";
    $fragmentIcon = "{$baseUrl}/schedules-direct/{$epg->uuid}/image/fragment-icon#untrusted";
    $fragmentImage = "{$baseUrl}/schedules-direct/{$epg->uuid}/image/fragment-image#untrusted";
    $userinfoBaseUrl = str_replace('://', '://untrusted@', $baseUrl);
    $userinfoIcon = "{$userinfoBaseUrl}/schedules-direct/{$epg->uuid}/image/userinfo-icon";
    $userinfoImage = "{$userinfoBaseUrl}/schedules-direct/{$epg->uuid}/image/userinfo-image";
    $externalIcon = 'https://public-artwork.example.test/external-icon.jpg';
    $externalImage = 'https://public-artwork.example.test/external-image.jpg';
    seedEpgProgrammeCache($epg, collect([
        [
            'title' => 'First-party artwork',
            'icon' => $schedulesDirectIcon,
            'images' => [
                ['url' => $schedulesDirectImages[0], 'type' => 'poster', 'width' => 1000, 'height' => 1500, 'orient' => 'P', 'size' => 3],
                ['url' => $schedulesDirectImages[1], 'type' => 'backdrop', 'width' => 1920, 'height' => 1080, 'orient' => 'L', 'size' => 4],
                ['url' => $untrustedSchedulesDirectImage, 'type' => 'banner', 'width' => 1000, 'height' => 1500, 'orient' => 'P', 'size' => 3],
            ],
        ],
        [
            'title' => 'Dot-segment artwork',
            'icon' => $dotSegmentIcon,
            'images' => [
                ['url' => $dotDotSegmentImage, 'type' => 'poster', 'width' => 1000, 'height' => 1500, 'orient' => 'P', 'size' => 3],
            ],
        ],
        [
            'title' => 'Query artwork',
            'icon' => $queryIcon,
            'images' => [
                ['url' => $queryImage, 'type' => 'poster', 'width' => 1000, 'height' => 1500, 'orient' => 'P', 'size' => 3],
            ],
        ],
        [
            'title' => 'Fragment artwork',
            'icon' => $fragmentIcon,
            'images' => [
                ['url' => $fragmentImage, 'type' => 'poster', 'width' => 1000, 'height' => 1500, 'orient' => 'P', 'size' => 3],
            ],
        ],
        [
            'title' => 'Userinfo artwork',
            'icon' => $userinfoIcon,
            'images' => [
                ['url' => $userinfoImage, 'type' => 'poster', 'width' => 1000, 'height' => 1500, 'orient' => 'P', 'size' => 3],
            ],
        ],
        [
            'title' => 'Public artwork',
            'icon' => $externalIcon,
            'images' => [
                ['url' => $externalImage, 'type' => 'poster', 'width' => 1000, 'height' => 1500, 'orient' => 'P', 'size' => 3],
            ],
        ],
    ])->map(function (array $programme, int $index): array {
        return ['source.schedules-direct', array_merge([
            'start' => now()->startOfDay()->addHours($index + 1)->toISOString(),
            'stop' => now()->startOfDay()->addHours($index + 2)->toISOString(),
            'subtitle' => '',
            'desc' => '',
            'category' => '',
            'rating' => '',
            'new' => false,
        ], $programme)];
    })->all());

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $xpath = new DOMXPath($document);

    expect($xpath->query('//programme[title="First-party artwork"]/icon[@src="'.$schedulesDirectIcon.'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="First-party artwork"]/icon[@src="'.$schedulesDirectImages[1].'"][@width="1920"][@height="1080"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="First-party artwork"]/icon[@src="'.$schedulesDirectImages[0].'"][@width="1000"][@height="1500"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="First-party artwork"]/image[text()="'.$schedulesDirectImages[0].'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="First-party artwork"]/image[text()="'.$schedulesDirectImages[1].'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Dot-segment artwork"]/icon[@src="'.$dotSegmentIcon.'" or @src="'.$dotDotSegmentImage.'"]'))->toHaveCount(0)
        ->and($xpath->query('//programme[title="Dot-segment artwork"]/image[text()="'.$dotDotSegmentImage.'"]'))->toHaveCount(0)
        ->and($xpath->query('//programme[title="Query artwork"]/icon[@src="'.LogoProxyController::generateProxyUrl($queryIcon).'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Query artwork"]/icon[@src="'.LogoProxyController::generateProxyUrl($queryImage).'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Query artwork"]/image[text()="'.LogoProxyController::generateProxyUrl($queryImage).'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Fragment artwork"]/icon[@src="'.LogoProxyController::generateProxyUrl($fragmentIcon).'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Fragment artwork"]/icon[@src="'.LogoProxyController::generateProxyUrl($fragmentImage).'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Fragment artwork"]/image[text()="'.LogoProxyController::generateProxyUrl($fragmentImage).'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Userinfo artwork"]/icon[@src="'.LogoProxyController::generateProxyUrl($userinfoIcon).'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Userinfo artwork"]/icon[@src="'.LogoProxyController::generateProxyUrl($userinfoImage).'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Userinfo artwork"]/image[text()="'.LogoProxyController::generateProxyUrl($userinfoImage).'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Public artwork"]/icon[@src="'.LogoProxyController::generateProxyUrl($externalIcon).'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Public artwork"]/icon[@src="'.LogoProxyController::generateProxyUrl($externalImage).'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Public artwork"]/image[text()="'.LogoProxyController::generateProxyUrl($externalImage).'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme/icon[@type or @orient or @size]'))->toHaveCount(0);
});

test('cached Schedules Direct artwork built on a different host than the serving request is still recognized as first-party', function () {
    // Regression test for the bug this feature originally shipped with: artwork URLs are built by
    // SchedulesDirectService::buildImageUrl() during the queued import job (where the base URL is forced to
    // config('app.url')), then re-evaluated here during a live HTTP request (where the base URL reflects the
    // request's actual Host header). Those two can legitimately differ on a self-hosted deployment reached via
    // a LAN IP, reverse proxy, or tunnel, so the match must not depend on host/scheme/port.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
        'enable_logo_proxy' => true,
    ]);
    $epg = Epg::factory()->for($user)->create([
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'is_cached' => true,
    ]);
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => 'source.schedules-direct-mismatched-host',
        'display_name' => 'Schedules Direct Mismatched Host Channel',
        'lang' => 'en',
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'stream_id' => 'schedules-direct-mismatched-host-channel',
        'title' => 'Schedules Direct Mismatched Host Channel',
        'channel' => 1,
        'group_id' => null,
    ]);

    // Built with a different host/scheme/port than whatever the test request resolves to.
    $mismatchedHostIcon = "https://queue-internal.example:8443/schedules-direct/{$epg->uuid}/image/mismatched-host-artwork";

    seedEpgProgrammeCache($epg, [
        ['source.schedules-direct-mismatched-host', [
            'start' => now()->startOfDay()->addHour()->toISOString(),
            'stop' => now()->startOfDay()->addHours(2)->toISOString(),
            'title' => 'Mismatched host artwork',
            'subtitle' => '',
            'desc' => '',
            'category' => '',
            'rating' => '',
            'new' => false,
            'icon' => $mismatchedHostIcon,
            'images' => [],
        ]],
    ]);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $xpath = new DOMXPath($document);

    expect($xpath->query('//programme[title="Mismatched host artwork"]/icon[@src="'.$mismatchedHostIcon.'"]'))->toHaveCount(1);
});

test('already internal programme artwork is not proxied again', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
        'enable_logo_proxy' => true,
    ]);
    $epg = Epg::factory()->for($user)->create([
        'url' => 'https://example.com/internal-art.xml',
        'is_cached' => true,
    ]);
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => 'source.internal-art',
        'display_name' => 'Internal Art Channel',
        'lang' => 'en',
    ]);
    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'stream_id' => 'internal-art-channel',
        'title' => 'Internal Art Channel',
        'channel' => 1,
        'group_id' => null,
    ]);
    $internalPoster = url('/media-server-image-proxy/programme/poster.jpg');
    seedEpgProgrammeCache($epg, [[
        'source.internal-art',
        [
            'start' => now()->startOfDay()->addHour()->toISOString(),
            'stop' => now()->startOfDay()->addHours(2)->toISOString(),
            'title' => 'Internal Art',
            'icon' => $internalPoster,
            'images' => [[
                'url' => $internalPoster,
                'type' => 'poster',
                'width' => 600,
                'height' => 900,
                'orient' => 'P',
                'size' => 3,
            ]],
        ],
    ]]);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz")->assertOk();
    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();
    $xpath = new DOMXPath($document);

    expect($xpath->query('//programme[title="Internal Art"]/icon[@src="'.$internalPoster.'"]'))->toHaveCount(2)
        ->and($xpath->query('//programme[title="Internal Art"]/image[text()="'.$internalPoster.'"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Internal Art"]//*[contains(@src, "logo-proxy.php")]'))->toHaveCount(0);
});

test('cached programme artwork is emitted as standard xmltv images with dimensioned legacy icons', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['dummy_epg' => false]);
    $epg = Epg::factory()->for($user)->create([
        'url' => 'https://example.com/standard-artwork.xml',
        'is_cached' => true,
    ]);
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => 'source.standard-artwork',
        'display_name' => 'Standard Artwork Channel',
        'icon' => 'https://example.com/channel-logo.png',
        'lang' => 'en',
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'stream_id' => 'standard-artwork-channel',
        'title' => 'Standard Artwork Channel',
        'logo' => 'https://example.com/channel-logo.png',
        'channel' => 1,
        'group_id' => null,
    ]);

    seedEpgProgrammeCache($epg, [
        ['source.standard-artwork', [
            'start' => now()->startOfDay()->addHour()->toISOString(),
            'stop' => now()->startOfDay()->addHours(2)->toISOString(),
            'title' => 'Standard Artwork',
            'subtitle' => '',
            'desc' => '',
            'category' => 'Drama',
            'episode_nums' => [['system' => 'onscreen', 'value' => 'S01E01']],
            'rating' => 'TV-14',
            'new' => true,
            'icon' => 'https://example.com/portrait-must-not-be-icon.jpg',
            'images' => [
                ['url' => 'https://example.com/poster.jpg', 'type' => 'poster', 'width' => 600, 'height' => 900, 'orient' => 'P', 'size' => 3, 'system' => 'schedulesdirect'],
                ['url' => 'https://example.com/backdrop.jpg', 'type' => 'backdrop', 'width' => 1280, 'height' => 720, 'orient' => 'L', 'size' => 3, 'system' => 'schedulesdirect'],
                ['url' => 'https://example.com/still.jpg', 'type' => 'still', 'width' => 640, 'height' => 360, 'orient' => 'L', 'size' => 3],
                ['url' => 'https://example.com/person.jpg', 'type' => 'person', 'width' => 300, 'height' => 450, 'orient' => 'P', 'size' => 2],
                ['url' => 'https://example.com/character.jpg', 'type' => 'character', 'width' => 0, 'height' => 0, 'orient' => 'P', 'size' => 2],
                ['url' => 'javascript:alert(1)', 'type' => 'poster', 'width' => 600, 'height' => 900, 'orient' => 'P', 'size' => 3],
                ['url' => 'https://example.com/banner.jpg', 'type' => 'banner', 'width' => 1280, 'height' => 300, 'orient' => 'L', 'size' => 3],
                ['url' => 'https://example.com/missing-dimensions.jpg', 'type' => 'backdrop', 'width' => 0, 'height' => 0, 'orient' => 'L', 'size' => 3],
            ],
        ]],
        ['source.standard-artwork', [
            'start' => now()->startOfDay()->addHours(3)->toISOString(),
            'stop' => now()->startOfDay()->addHours(4)->toISOString(),
            'title' => 'Poster Only',
            'subtitle' => '',
            'desc' => '',
            'category' => '',
            'episode_nums' => [],
            'rating' => '',
            'new' => false,
            'icon' => '',
            'images' => [
                ['url' => 'https://example.com/poster-only.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750, 'orient' => 'P', 'size' => 3],
                ['url' => 'https://example.com/square.jpg', 'type' => 'poster', 'width' => 500, 'height' => 500, 'orient' => 'P', 'size' => 3],
            ],
        ]],
        ['source.standard-artwork', [
            'start' => now()->startOfDay()->addHours(5)->toISOString(),
            'stop' => now()->startOfDay()->addHours(6)->toISOString(),
            'title' => 'Canonical Posters Forward',
            'subtitle' => '',
            'desc' => '',
            'category' => '',
            'episode_nums' => [],
            'rating' => '',
            'new' => false,
            'icon' => '',
            'images' => [
                ['url' => 'https://example.com/small-poster.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750, 'orient' => 'P', 'size' => 3],
                ['url' => 'https://example.com/large-poster.jpg', 'type' => 'poster', 'width' => 800, 'height' => 1200, 'orient' => 'P', 'size' => 3],
                ['url' => 'https://example.com/large-poster.jpg', 'type' => 'poster', 'width' => 800, 'height' => 1200, 'orient' => 'P', 'size' => 3],
            ],
        ]],
        ['source.standard-artwork', [
            'start' => now()->startOfDay()->addHours(7)->toISOString(),
            'stop' => now()->startOfDay()->addHours(8)->toISOString(),
            'title' => 'Canonical Posters Reverse',
            'subtitle' => '',
            'desc' => '',
            'category' => '',
            'episode_nums' => [],
            'rating' => '',
            'new' => false,
            'icon' => '',
            'images' => [
                ['url' => 'https://example.com/large-poster.jpg', 'type' => 'poster', 'width' => 800, 'height' => 1200, 'orient' => 'P', 'size' => 3],
                ['url' => 'https://example.com/small-poster.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750, 'orient' => 'P', 'size' => 3],
            ],
        ]],
        ['source.standard-artwork', [
            'start' => now()->startOfDay()->addHours(9)->toISOString(),
            'stop' => now()->startOfDay()->addHours(10)->toISOString(),
            'title' => 'Conflicting Posters',
            'subtitle' => '',
            'desc' => '',
            'category' => '',
            'episode_nums' => [],
            'rating' => '',
            'new' => false,
            'icon' => '',
            'images' => [
                ['url' => 'https://example.com/geometry-conflict.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750, 'orient' => 'P', 'size' => 3],
                ['url' => 'https://example.com/geometry-conflict.jpg', 'type' => 'poster', 'width' => 1280, 'height' => 720, 'orient' => 'L', 'size' => 3],
                ['url' => 'https://example.com/role-conflict.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750, 'orient' => 'P', 'size' => 3],
                ['url' => 'https://example.com/role-conflict.jpg', 'type' => 'backdrop', 'width' => 1280, 'height' => 720, 'orient' => 'L', 'size' => 3],
            ],
        ]],
    ]);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');
    $xml = gzdecode($response->getContent());
    $dtd = realpath(base_path('tests/Fixtures/xmltv/xmltv.dtd'));
    $document = new DOMDocument;
    $document->loadXML(str_replace('SYSTEM "xmltv.dtd"', 'SYSTEM "file://'.$dtd.'"', $xml));
    expect($document->validate())->toBeTrue();

    $xpath = new DOMXPath($document);
    $programme = $xpath->query('//programme[title="Standard Artwork"]')->item(0);
    $children = [];
    foreach ($programme->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $children[] = $child->tagName;
        }
    }

    expect($xpath->query('//channel[@id="standard-artwork-channel"]/icon[@src="https://example.com/channel-logo.png"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Standard Artwork"]/icon'))->toHaveCount(5)
        ->and($xpath->query('//programme[title="Standard Artwork"]/icon[@src="https://example.com/portrait-must-not-be-icon.jpg"][not(@width)][not(@height)]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Standard Artwork"]/icon[@src="https://example.com/backdrop.jpg"][@width="1280"][@height="720"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Standard Artwork"]/icon[@src="https://example.com/poster.jpg"][@width="600"][@height="900"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Standard Artwork"]/icon[@src="https://example.com/still.jpg"][@width="640"][@height="360"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Standard Artwork"]/icon[@src="https://example.com/person.jpg"][@width="300"][@height="450"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Standard Artwork"]/icon[@type or @orient or @size]'))->toHaveCount(0)
        ->and($xpath->query('//programme[title="Standard Artwork"]/image[@type="poster"][@size="3"][@orient="P"][@system="schedulesdirect"][text()="https://example.com/poster.jpg"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Standard Artwork"]/image[@type="backdrop"][@orient="L"]'))->toHaveCount(2)
        ->and($xpath->query('//programme[title="Standard Artwork"]/image[@type="still"][@orient="L"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Standard Artwork"]/image[@type="person"][@orient="P"][text()="https://example.com/person.jpg"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Standard Artwork"]/image[@type="character"][@orient="P"][text()="https://example.com/character.jpg"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Standard Artwork"]/image[contains(text(), "missing-dimensions")][@type="backdrop"][@orient="L"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Standard Artwork"]/image[contains(text(), "javascript:") or contains(text(), "banner.jpg")]'))->toHaveCount(0)
        ->and($children)->toBe(['title', 'category', 'icon', 'icon', 'icon', 'icon', 'icon', 'episode-num', 'new', 'rating', 'image', 'image', 'image', 'image', 'image', 'image'])
        ->and($xpath->query('//programme[title="Poster Only"]/icon'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Poster Only"]/icon[@src="https://example.com/poster-only.jpg"][@width="500"][@height="750"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Poster Only"]/icon[contains(@src, "square.jpg")]'))->toHaveCount(0)
        ->and($xpath->query('//programme[title="Canonical Posters Forward"]/image[@type="poster"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Canonical Posters Forward"]/image[@type="poster"][text()="https://example.com/large-poster.jpg"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Canonical Posters Forward"]/icon[@src="https://example.com/small-poster.jpg"][@width="500"][@height="750"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Canonical Posters Forward"]/icon[@src="https://example.com/large-poster.jpg"][@width="800"][@height="1200"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Canonical Posters Reverse"]/image[@type="poster"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Canonical Posters Reverse"]/image[@type="poster"][text()="https://example.com/large-poster.jpg"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Conflicting Posters"]/image'))->toHaveCount(0)
        ->and($xpath->query('//programme[title="Conflicting Posters"]/icon'))->toHaveCount(0);
});

test('cache import converts standard images and legacy typed icons without losing verified landscape geometry', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['dummy_epg' => false]);
    $epg = Epg::factory()->for($user)->create([
        'url' => 'https://example.com/mixed-artwork.xml',
        'is_cached' => false,
    ]);
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => 'source.mixed-artwork',
        'display_name' => 'Mixed Artwork Channel',
        'lang' => 'en',
    ]);
    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'stream_id' => 'mixed-artwork-channel',
        'title' => 'Mixed Artwork Channel',
        'channel' => 1,
        'group_id' => null,
    ]);

    $start = now()->startOfDay()->addHour()->format('YmdHis O');
    $stop = now()->startOfDay()->addHours(2)->format('YmdHis O');
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <channel id="source.mixed-artwork"><display-name>Mixed Artwork Channel</display-name></channel>
  <programme start="{$start}" stop="{$stop}" channel="source.mixed-artwork">
    <title>Mixed Artwork</title>
    <category>Drama</category>
    <icon src="https://example.com/landscape.jpg" width="1280" height="720" />
    <icon src="https://example.com/legacy-poster.jpg" type="poster" width="500" height="750" orient="P" size="3" />
    <episode-num system="onscreen">S01E01</episode-num>
    <new />
    <rating><value>TV-14</value></rating>
    <image type="backdrop" size="3" orient="L" system="schedulesdirect">https://example.com/landscape.jpg</image>
    <image type="poster" size="3" orient="P" system="schedulesdirect">https://example.com/standard-poster.jpg</image>
    <image type="person" size="2" orient="P">https://example.com/person-imported.jpg</image>
  </programme>
  <programme start="{$start}" stop="{$stop}" channel="source.mixed-artwork">
    <title>Legacy Icons Only</title>
    <icon src="https://example.com/legacy-square.jpg" width="600" height="600" />
    <icon src="https://example.com/legacy-wide.jpg" width="1280" height="720" />
    <icon src="https://example.com/legacy-portrait.jpg" width="500" height="750" />
  </programme>
</tv>
XML;
    Storage::disk('local')->put($epg->file_path, gzencode($xml));

    expect(app(EpgCacheService::class)->cacheEpgData($epg))->toBeTrue();

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");
    $response->assertOk();
    $document = new DOMDocument;
    $output = gzdecode($response->getContent());
    $dtd = realpath(base_path('tests/Fixtures/xmltv/xmltv.dtd'));
    $document->loadXML(str_replace('SYSTEM "xmltv.dtd"', 'SYSTEM "file://'.$dtd.'"', $output));
    expect($document->validate())->toBeTrue();

    $xpath = new DOMXPath($document);
    expect($xpath->query('//programme[title="Mixed Artwork"]/icon'))->toHaveCount(3)
        ->and($xpath->query('//programme[title="Mixed Artwork"]/icon[@src="https://example.com/landscape.jpg"]'))->toHaveCount(2)
        ->and($xpath->query('//programme[title="Mixed Artwork"]/icon[@src="https://example.com/landscape.jpg"][@width="1280"][@height="720"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Mixed Artwork"]/icon[@src="https://example.com/legacy-poster.jpg"][@width="500"][@height="750"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Mixed Artwork"]/icon[@type or @orient or @size]'))->toHaveCount(0)
        ->and($xpath->query('//programme[title="Mixed Artwork"]/image[@type="backdrop"][@orient="L"][text()="https://example.com/landscape.jpg"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Mixed Artwork"]/image[@type="poster"][@orient="P"][text()="https://example.com/legacy-poster.jpg"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Mixed Artwork"]/image[@type="poster"][@orient="P"][text()="https://example.com/standard-poster.jpg"]'))->toHaveCount(0)
        ->and($xpath->query('//programme[title="Mixed Artwork"]/image[@type="person"][@orient="P"][text()="https://example.com/person-imported.jpg"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Legacy Icons Only"]/icon'))->toHaveCount(4)
        ->and($xpath->query('//programme[title="Legacy Icons Only"]/icon[@src="https://example.com/legacy-square.jpg"]'))->toHaveCount(2)
        ->and($xpath->query('//programme[title="Legacy Icons Only"]/icon[@src="https://example.com/legacy-wide.jpg"][@width="1280"][@height="720"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Legacy Icons Only"]/icon[@src="https://example.com/legacy-portrait.jpg"][@width="500"][@height="750"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Legacy Icons Only"]/image'))->toHaveCount(0);
});

test('legacy scalar episode numbers emit only valid xmltv namespace identities', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
    ]);
    $epg = Epg::factory()->for($user)->create([
        'url' => 'https://example.com/legacy.xml',
        'is_cached' => true,
    ]);
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => 'source.legacy',
        'display_name' => 'Legacy Channel',
        'lang' => 'en',
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'stream_id' => 'legacy-channel',
        'title' => 'Legacy Channel',
        'channel' => 1,
        'group_id' => null,
    ]);

    // Deliberately a legacy JSONL fixture (no `programmes.sqlite`): a programme
    // that carries only the scalar `episode_num` and no `episode_nums` array can
    // only come from a v1 cache written before that field existed. Every cache
    // the current parser writes - JSONL or SQLite - always seeds `episode_nums`
    // (via EpgProgrammeStore::EMPTY_PROGRAMME), so the scalar-legacy branch in
    // EpisodeNumberNormalizer::forProgramme() is unreachable from the SQLite
    // store by construction. This pins that fallback on the format it applies to.
    $date = now()->format('Y-m-d');
    $cacheDirectory = "epg-cache/{$epg->uuid}/v2";
    Storage::disk('local')->put("{$cacheDirectory}/metadata.json", json_encode([
        'cache_created' => time(),
        'cache_version' => 'v2',
    ], JSON_THROW_ON_ERROR));

    $records = collect([
        ['title' => 'Valid Legacy', 'episode_num' => '0.4.'],
        ['title' => 'Provider Legacy', 'episode_num' => 'EP012345670089'],
        ['title' => 'Zero Legacy', 'episode_num' => '0'],
        ['title' => 'Malformed Legacy', 'episode_num' => '1..2..3'],
    ])->map(function (array $programme, int $index): string {
        return json_encode([
            'channel' => 'source.legacy',
            'programme' => array_merge([
                'start' => now()->startOfDay()->addHours($index + 1)->toISOString(),
                'stop' => now()->startOfDay()->addHours($index + 2)->toISOString(),
                'subtitle' => '',
                'desc' => '',
                'category' => '',
                'rating' => '',
                'icon' => '',
                'images' => [],
                'new' => false,
            ], $programme),
        ], JSON_THROW_ON_ERROR);
    })->implode('
').'
';

    Storage::disk('local')->put("{$cacheDirectory}/programmes-{$date}.jsonl", $records);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $episodeNumbers = [];
    foreach ($document->getElementsByTagName('episode-num') as $episodeNumber) {
        $episodeNumbers[] = [
            'system' => $episodeNumber->getAttribute('system'),
            'value' => $episodeNumber->textContent,
        ];
    }

    expect($episodeNumbers)->toBe([
        ['system' => 'xmltv_ns', 'value' => '0.4.'],
    ]);
});

test('standard dummy programmes do not copy channel branding into programme artwork', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => true,
        'dummy_epg_length' => 7200,
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'stream_id' => 'dummy-channel',
        'title' => 'Dummy Channel',
        'logo' => 'https://example.com/channel-logo.png',
        'channel' => 1,
        'aed_profile_id' => null,
    ]);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $xpath = new DOMXPath($document);

    expect($xpath->query('//channel[@id="dummy-channel"]/icon'))->toHaveCount(1)
        ->and($xpath->query('//programme[@channel="dummy-channel"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[@channel="dummy-channel"]/icon'))->toHaveCount(0);
});

test('aed dummy programmes are generated even when the playlist dummy epg toggle is disabled', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
    ]);
    $aedProfile = new AedProfile;
    $aedProfile->forceFill([
        'user_id' => $user->id,
        'name' => 'Independent AED',
        'event_duration_minutes' => 7200,
    ])->save();

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'stream_id' => 'aed-independent-channel',
        'title' => 'AED Independent Channel',
        'channel' => 1,
        'aed_profile_id' => $aedProfile->id,
    ]);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $xpath = new DOMXPath($document);

    expect($xpath->query('//channel[@id="aed-independent-channel"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[@channel="aed-independent-channel"]'))->toHaveCount(1);
});

test('aed dummy programmes do not fall back to channel branding for programme artwork', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => true,
    ]);
    $aedProfile = new AedProfile;
    $aedProfile->forceFill([
        'user_id' => $user->id,
        'name' => 'No Artwork',
        'logo_url' => null,
        'event_duration_minutes' => 7200,
    ])->save();

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'stream_id' => 'aed-dummy-channel',
        'title' => 'AED Dummy Channel',
        'logo' => 'https://example.com/channel-logo.png',
        'channel' => 1,
        'aed_profile_id' => $aedProfile->id,
    ]);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $xpath = new DOMXPath($document);

    expect($xpath->query('//channel[@id="aed-dummy-channel"]/icon'))->toHaveCount(1)
        ->and($xpath->query('//programme[@channel="aed-dummy-channel"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[@channel="aed-dummy-channel"]/icon'))->toHaveCount(0);
});

test('playlist dummy_epg_days controls the number of standard dummy programmes generated', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => true,
        'dummy_epg_length' => 1440,
        'dummy_epg_days' => 2,
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'stream_id' => 'short-window-channel',
        'title' => 'Short Window Channel',
        'channel' => 1,
        'aed_profile_id' => null,
    ]);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $xpath = new DOMXPath($document);

    expect($xpath->query('//programme[@channel="short-window-channel"]'))->toHaveCount(2);
});

test('aed profile dummy_epg_days overrides the playlist dummy_epg_days for that channel', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => true,
        'dummy_epg_days' => 5,
    ]);
    $aedProfile = new AedProfile;
    $aedProfile->forceFill([
        'user_id' => $user->id,
        'name' => 'Short Window AED',
        'event_duration_minutes' => 1440,
        'dummy_epg_days' => 2,
    ])->save();

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'stream_id' => 'aed-short-window-channel',
        'title' => 'AED Short Window Channel',
        'channel' => 1,
        'aed_profile_id' => $aedProfile->id,
    ]);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $xpath = new DOMXPath($document);

    expect($xpath->query('//programme[@channel="aed-short-window-channel"]'))->toHaveCount(2);
});

test('custom playlist dummy_epg_days controls the number of standard dummy programmes generated', function () {
    $user = User::factory()->create();
    $playlist = CustomPlaylist::factory()->for($user)->create([
        'dummy_epg' => true,
        'dummy_epg_length' => 1440,
        'dummy_epg_days' => 2,
    ]);

    $channel = Channel::factory()->for($user)->create([
        'enabled' => true,
        'is_vod' => false,
        'stream_id' => 'custom-short-window-channel',
        'title' => 'Custom Short Window Channel',
        'aed_profile_id' => null,
    ]);
    $playlist->channels()->attach($channel->id);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $xpath = new DOMXPath($document);

    expect($xpath->query('//programme[@channel="custom-short-window-channel"]'))->toHaveCount(2);
});

test('merged playlist dummy_epg_days controls the number of standard dummy programmes generated', function () {
    $user = User::factory()->create();
    $sourcePlaylist = Playlist::factory()->for($user)->create();
    $merged = MergedPlaylist::factory()->for($user)->create([
        'dummy_epg' => true,
        'dummy_epg_length' => 1440,
        'dummy_epg_days' => 2,
    ]);
    $merged->playlists()->attach($sourcePlaylist->id);

    Channel::factory()->for($user)->for($sourcePlaylist)->create([
        'enabled' => true,
        'is_vod' => false,
        'stream_id' => 'merged-short-window-channel',
        'title' => 'Merged Short Window Channel',
        'channel' => 1,
        'aed_profile_id' => null,
    ]);

    $response = $this->get("/{$merged->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $xpath = new DOMXPath($document);

    expect($xpath->query('//programme[@channel="merged-short-window-channel"]'))->toHaveCount(2);
});

test('aed pre-event padding stops at the dummy epg window for events far in the future', function () {
    // Regression test for #1549: pre-event slots were generated all the way to the event,
    // ignoring dummy_epg_days, so a far-off event produced months of programmes.
    Carbon::setTestNow(Carbon::create(2026, 9, 28, 10, 0, 0, 'UTC'));

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => true,
        'dummy_epg_days' => 5,
    ]);
    $aedProfile = new AedProfile;
    $aedProfile->forceFill([
        'user_id' => $user->id,
        'name' => 'Far Future AED',
        'time_regex' => '(\d{1,2}:\d{2})',
        'time_format' => 'H:i',
        'date_regex' => '\((\d{4}-\d{2}-\d{2})',
        'date_format' => 'Y-m-d',
        'source_timezone' => 'UTC',
        'output_timezone' => 'UTC',
        'event_duration_minutes' => 1440,
        'dummy_epg_days' => 2,
        'pre_event_format' => 'Live in {time_until}: {title}',
    ])->save();

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'stream_id' => 'aed-far-future-channel',
        'title' => 'Big Match (2027-09-27 19:00)',
        'channel' => 1,
        'aed_profile_id' => $aedProfile->id,
    ]);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk();

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $xpath = new DOMXPath($document);
    $programmes = $xpath->query('//programme[@channel="aed-far-future-channel"]');

    // Two 1-day pre-event slots inside the 2-day window, and no event programme (it starts after the window)
    expect($programmes)->toHaveCount(2)
        ->and($programmes->item(1)->getAttribute('stop'))->toStartWith('20260930000000');

    Carbon::setTestNow();
});
