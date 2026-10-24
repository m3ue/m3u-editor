<?php

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\EpgCacheService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    $this->travelTo(now()->utc()->setTime(12, 0));

    $user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
        'enable_logo_proxy' => false,
    ]);
    $this->epg = Epg::factory()->for($user)->create(['url' => 'https://example.com/artwork.xml']);
    $epgChannel = EpgChannel::factory()->for($user)->for($this->epg)->create(['channel_id' => 'artwork.test']);
    $this->channel = Channel::factory()->for($user)->for($this->playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'group_id' => null,
        'stream_id' => 'artwork-test',
        'epg_channel_id' => $epgChannel->id,
    ]);
    $auth = PlaylistAuth::create([
        'name' => 'Synthetic artwork test',
        'username' => 'artwork-test-user',
        'password' => 'artwork-test-password',
        'enabled' => true,
        'user_id' => $user->id,
    ]);
    $this->playlist->playlistAuths()->attach($auth);
    $this->credentials = ['username' => $auth->username, 'password' => 'artwork-test-password'];

    $start = now()->subMinutes(5)->format('YmdHis O');
    $stop = now()->addMinutes(55)->format('YmdHis O');
    $xml = <<<XML
    <tv>
      <channel id="artwork.test"><display-name>Artwork Test</display-name></channel>
      <programme channel="artwork.test" start="{$start}" stop="{$stop}">
        <title>Synthetic Artwork</title>
        <icon src="https://example.com/poster.jpg" type="poster" orient="P" width="500" height="750" size="3"/>
        <icon src="https://example.com/backdrop.jpg" type="backdrop" width="1280" height="720"/>
        <icon src="https://example.com/still.jpg" type="still" width="1920" height="1080"/>
      </programme>
    </tv>
    XML;
    Storage::disk('local')->put($this->epg->file_path, gzencode($xml));
    expect(app(EpgCacheService::class)->cacheEpgData($this->epg))->toBeTrue();
});

it('persists first-icon geometry and roles in the real SQLite programme store', function () {
    expect($this->epg->fresh()->is_cached)->toBeTrue();
    $path = Storage::disk('local')->path("epg-cache/{$this->epg->uuid}/v2/programmes.sqlite");
    expect(file_get_contents($path, length: 16))->toBe("SQLite format 3\0");

    $programmes = (new EpgCacheService)->getCachedProgrammes($this->epg->fresh(), now()->toDateString(), ['artwork.test']);
    expect($programmes['artwork.test'])->toHaveCount(1)
        ->and($programmes['artwork.test'][0]['icon'])->toBe('https://example.com/poster.jpg')
        ->and($programmes['artwork.test'][0]['images'][0])->toBe([
            'url' => 'https://example.com/poster.jpg',
            'type' => 'poster',
            'width' => 500,
            'height' => 750,
            'orient' => 'P',
            'size' => 3,
        ]);
});

it('serves imported first-icon geometry and independent artwork roles through the XMLTV HTTP handler', function () {
    $response = $this->get("/{$this->playlist->uuid}/epg.xml.gz")
        ->assertOk()->assertHeader('Content-Type', 'application/gzip');
    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->streamedContent())))->toBeTrue();
    $xpath = new DOMXPath($document);

    expect($xpath->evaluate('string(//programme/icon[@src="https://example.com/poster.jpg"]/@width)'))->toBe('500')
        ->and($xpath->evaluate('string(//programme/icon[@src="https://example.com/poster.jpg"]/@height)'))->toBe('750')
        ->and($xpath->evaluate('string(//programme/image[@type="poster"])'))->toBe('https://example.com/poster.jpg')
        ->and($xpath->evaluate('string(//programme/image[@type="backdrop"])'))->toBe('https://example.com/backdrop.jpg')
        ->and($xpath->evaluate('string(//programme/image[@type="still"])'))->toBe('https://example.com/still.jpg');
});

it('serves imported poster geometry and separate stills through the Xtream HTTP handler', function (string $action) {
    $params = $this->credentials + [
        'action' => $action,
        'details' => 1,
        $action === 'get_epg_batch' ? 'stream_ids' : 'stream_id' => (string) $this->channel->id,
    ];
    $response = $this->getJson(route('xtream.api.player').'?'.http_build_query($params))->assertOk();
    $prefix = $action === 'get_epg_batch' ? $this->channel->id.'.' : '';
    $response->assertJsonPath($prefix.'epg_listings.0.poster_url', 'https://example.com/poster.jpg')
        ->assertJsonPath($prefix.'epg_listings.0.poster_width', 500)
        ->assertJsonPath($prefix.'epg_listings.0.poster_height', 750)
        ->assertJsonPath($prefix.'epg_listings.0.backdrop_url', 'https://example.com/backdrop.jpg')
        ->assertJsonPath($prefix.'epg_listings.0.still_url', 'https://example.com/still.jpg')
        ->assertJsonPath($prefix.'epg_listings.0.still_width', 1920)
        ->assertJsonPath($prefix.'epg_listings.0.still_height', 1080);
})->with(['get_short_epg', 'get_simple_data_table', 'get_epg_batch']);
