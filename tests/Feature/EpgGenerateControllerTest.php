<?php

use App\Events\EpgCreated;
use App\Events\EpgDeleted;
use App\Events\EpgUpdated;
use App\Events\PlaylistCreated;
use App\Events\PlaylistDeleted;
use App\Events\PlaylistUpdated;
use App\Models\AedProfile;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\EpgCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
    ]);

    // Clean up any leftover playlist EPG cache files from previous runs
    Storage::disk('local')->deleteDirectory('playlist-epg-files');
});

/**
 * @param  array<string, mixed>  $programme
 */
function createCachedProgrammeExportFixture(array $programme): Playlist
{
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
    ]);
    $epg = Epg::factory()->for($user)->create([
        'url' => 'https://example.com/export.xml',
        'is_cached' => true,
    ]);
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => 'source.export',
        'display_name' => 'Export Channel',
        'lang' => 'en',
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'stream_id' => 'export-channel',
        'title' => 'Export Channel',
        'channel' => 1,
    ]);

    $cacheDirectory = "epg-cache/{$epg->uuid}/v2";
    Storage::disk('local')->put("{$cacheDirectory}/metadata.json", json_encode([
        'cache_created' => time(),
        'cache_version' => 'v2',
    ], JSON_THROW_ON_ERROR));
    Storage::disk('local')->put(
        "{$cacheDirectory}/programmes-".now()->format('Y-m-d').'.jsonl',
        json_encode([
            'channel' => 'source.export',
            'programme' => array_merge([
                'start' => now()->startOfDay()->addHour()->toISOString(),
                'stop' => now()->startOfDay()->addHours(2)->toISOString(),
                'title' => 'Export Programme',
                'subtitle' => '',
                'desc' => '',
                'category' => '',
                'episode_num' => '',
                'episode_nums' => [],
                'rating' => '',
                'icon' => '',
                'images' => [],
                'new' => false,
                'previously_shown' => false,
                'premiere' => false,
                'urls' => [],
                'production_year' => null,
            ], $programme),
        ], JSON_THROW_ON_ERROR)."\n",
    );

    return $playlist;
}

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

    $cacheLine = Storage::disk('local')->get("epg-cache/{$epg->uuid}/v2/programmes-{$date}.jsonl");
    $cachedProgramme = json_decode(trim($cacheLine), true, flags: JSON_THROW_ON_ERROR)['programme'];
    $expectedEpisodeNumbers = [
        ['system' => 'xmltv_ns', 'value' => '1 . 4/10 .'],
        ['system' => 'dd_progid', 'value' => 'EP012345670089'],
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

    $xpath = new DOMXPath($document);

    expect($episodeNumbers)->toBe($expectedEpisodeNumbers)
        ->and($xpath->query('//programme[@channel="identity-channel"]/icon[@src="https://example.com/programme-artwork.jpg"]'))->toHaveCount(1);
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
    ]);

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
    })->implode("\n")."\n";

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

test('cached epg generation exports programme urls and structural signals', function () {
    $playlist = createCachedProgrammeExportFixture([
        'urls' => [
            ['system' => 'imdb', 'value' => 'https://www.imdb.com/title/tt0090390/'],
            ['system' => '', 'value' => 'https://example.com/programmes/export'],
            ['system' => 'unsafe', 'value' => 'javascript:alert(1)'],
        ],
        'production_year' => 2024,
        'previously_shown' => true,
        'premiere' => true,
    ]);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();

    $xpath = new DOMXPath($document);
    $urls = [];
    foreach ($xpath->query('//programme[@channel="export-channel"]/url') as $url) {
        $urls[] = [
            'system' => $url->hasAttribute('system') ? $url->getAttribute('system') : null,
            'value' => $url->textContent,
        ];
    }

    expect($urls)->toBe([
        ['system' => 'imdb', 'value' => 'https://www.imdb.com/title/tt0090390/'],
        ['system' => null, 'value' => 'https://example.com/programmes/export'],
    ])->and($xpath->query('//programme[@channel="export-channel"]/date[text()="2024"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[@channel="export-channel"]/previously-shown'))->toHaveCount(1)
        ->and($xpath->query('//programme[@channel="export-channel"]/premiere'))->toHaveCount(1);
});

test('cached epg generation exports deterministic dtd valid programme artwork', function () {
    $primaryIconUrl = 'https://example.com/artwork/primary.jpg';
    $playlist = createCachedProgrammeExportFixture([
        'desc' => 'Artwork description',
        'production_year' => 2024,
        'category' => 'Drama',
        'urls' => [
            ['system' => 'imdb', 'value' => 'https://www.imdb.com/title/tt0090390/'],
        ],
        'episode_nums' => [
            ['system' => 'onscreen', 'value' => 'S01E02'],
        ],
        'previously_shown' => true,
        'premiere' => true,
        'rating' => 'TV-14',
        'icon' => $primaryIconUrl,
        'new' => true,
        'images' => [
            [
                'url' => 'https://example.com/artwork/backdrop.jpg',
                'type' => 'backdrop',
                'width' => 1280,
                'height' => 720,
                'orient' => 'L',
                'size' => 3,
                'system' => 'tmdb',
            ],
            [
                'url' => 'https://example.com/artwork/poster.jpg',
                'type' => 'poster',
                'width' => 300,
                'height' => 450,
                'orient' => '',
                'size' => 2,
            ],
            [
                'url' => 'https://example.com/artwork/still.jpg',
                'type' => 'still',
                'width' => -1,
                'height' => -2,
                'orient' => 'square',
                'size' => 4,
            ],
            [
                'url' => 'https://example.com/artwork/logo.png',
                'type' => 'logo',
                'width' => 600,
                'height' => 200,
                'orient' => 'L',
                'size' => 3,
            ],
            [
                'url' => 'https://example.com/artwork/backdrop.jpg',
                'type' => 'poster',
                'orient' => 'P',
                'size' => 1,
            ],
            [
                'url' => $primaryIconUrl,
                'type' => 'poster',
                'orient' => 'P',
                'size' => 3,
            ],
            [
                'url' => 'not-a-url',
                'type' => 'poster',
                'orient' => 'P',
                'size' => 1,
            ],
        ],
    ]);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertOk()->assertHeader('Content-Type', 'application/gzip');

    $xml = gzdecode($response->getContent());
    $dtdPath = realpath(base_path('tests/Fixtures/xmltv-programme-export.dtd'));
    expect($dtdPath)->not->toBeFalse();

    $xmlWithLocalDtd = str_replace(
        '<!DOCTYPE tv SYSTEM "xmltv.dtd">',
        '<!DOCTYPE tv SYSTEM "file://'.$dtdPath.'">',
        $xml,
    );

    $previousInternalErrors = libxml_use_internal_errors(true);
    libxml_clear_errors();

    try {
        $document = new DOMDocument;
        expect($document->loadXML($xmlWithLocalDtd, LIBXML_DTDLOAD))->toBeTrue()
            ->and($document->validate())->toBeTrue();
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousInternalErrors);
    }

    $xpath = new DOMXPath($document);
    $programmePath = '//programme[@channel="export-channel"]';
    expect($xpath->query($programmePath.'/icon'))->toHaveCount(1)
        ->and($xpath->query($programmePath.'/icon[@src="'.$primaryIconUrl.'"]'))->toHaveCount(1)
        ->and($xpath->query($programmePath.'/icon[@type or @orient or @size]'))->toHaveCount(0)
        ->and($xpath->query($programmePath.'/image[@width or @height]'))->toHaveCount(0);

    $images = [];
    foreach ($xpath->query($programmePath.'/image') as $image) {
        $images[] = [
            'url' => $image->textContent,
            'type' => $image->hasAttribute('type') ? $image->getAttribute('type') : null,
            'size' => $image->hasAttribute('size') ? $image->getAttribute('size') : null,
            'orient' => $image->hasAttribute('orient') ? $image->getAttribute('orient') : null,
            'system' => $image->hasAttribute('system') ? $image->getAttribute('system') : null,
        ];
    }

    expect($images)->toBe([
        [
            'url' => 'https://example.com/artwork/backdrop.jpg',
            'type' => 'backdrop',
            'size' => '3',
            'orient' => 'L',
            'system' => 'tmdb',
        ],
        [
            'url' => 'https://example.com/artwork/poster.jpg',
            'type' => 'poster',
            'size' => '2',
            'orient' => 'P',
            'system' => null,
        ],
        [
            'url' => 'https://example.com/artwork/still.jpg',
            'type' => 'still',
            'size' => null,
            'orient' => null,
            'system' => null,
        ],
        [
            'url' => 'https://example.com/artwork/logo.png',
            'type' => null,
            'size' => '3',
            'orient' => 'L',
            'system' => null,
        ],
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
