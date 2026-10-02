<?php

use App\Models\Epg;
use App\Models\User;
use App\Services\SchedulesDirectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
});

it('fetches station artwork and includes in XMLTV', function () {
    $user = User::factory()->create();

    $epg = Epg::factory()->create([
        'user_id' => $user->id,
        'sd_username' => 'test@example.com',
        'sd_password' => 'password',
        'sd_token' => 'valid-token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_ids' => ['USA-NY12345-X'],
        'sd_station_ids' => ['12345', '67890'],
        'sd_days_to_import' => 1,
    ]);

    // Mock station logos API response
    Http::fake([
        'json.schedulesdirect.org/20141201/metadata/stations' => Http::response([
            [
                'stationID' => '12345',
                'stationLogo' => [
                    ['URL' => 'https://example.com/logo1.png'],
                ],
            ],
            [
                'stationID' => '67890',
                'stationLogo' => [
                    ['URL' => 'https://example.com/logo2.png'],
                ],
            ],
        ]),
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response([
            'map' => [
                ['stationID' => '12345', 'channel' => '1.1'],
                ['stationID' => '67890', 'channel' => '2.1'],
            ],
            'stations' => [
                [
                    'stationID' => '12345',
                    'name' => 'Test Channel 1',
                    'callsign' => 'TEST1',
                    'stationLogo' => [
                        ['URL' => 'https://example.com/logo1.png', 'height' => 270, 'width' => 360],
                    ],
                ],
                [
                    'stationID' => '67890',
                    'name' => 'Test Channel 2',
                    'callsign' => 'TEST2',
                    'stationLogo' => [
                        ['URL' => 'https://example.com/logo2.png', 'height' => 270, 'width' => 360],
                    ],
                ],
            ],
        ]),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([
            [
                'stationID' => '12345',
                'programs' => [
                    [
                        'programID' => 'EP123',
                        'airDateTime' => '2025-09-18T20:00:00Z',
                        'duration' => 3600,
                    ],
                ],
            ],
        ]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([
            [
                'programID' => 'EP123',
                'titles' => [['title120' => 'Test Program']],
                'descriptions' => [
                    'description1000' => [['description' => 'Test description']],
                ],
            ],
        ]),
        'json.schedulesdirect.org/20141201/metadata/programs' => Http::response([
            [
                'programID' => 'EP123',
                'data' => [
                    'Ep' => [
                        ['URI' => 'https://example.com/program123.jpg'],
                    ],
                ],
            ],
        ]),
    ]);

    $service = new SchedulesDirectService;

    // Ensure storage directory is clean
    Storage::fake('local');

    $service->syncEpgData($epg);

    // Check that the XMLTV file was created
    expect(Storage::disk('local')->exists($epg->file_path))->toBeTrue();

    $xmlContent = Storage::disk('local')->get($epg->file_path);

    // Check for channel icons
    expect($xmlContent)->toContain('<channel id="12345">');
    expect($xmlContent)->toContain('<icon src="https://example.com/logo1.png" />');
    expect($xmlContent)->toContain('<channel id="67890">');
    expect($xmlContent)->toContain('<icon src="https://example.com/logo2.png" />');

    // Check for program content (program artwork is disabled for now due to API format issues)
    expect($xmlContent)->toContain('<programme channel="12345"');
});

it('handles missing artwork gracefully', function () {
    $user = User::factory()->create();

    $epg = Epg::factory()->create([
        'user_id' => $user->id,
        'sd_username' => 'test@example.com',
        'sd_password' => 'password',
        'sd_token' => 'valid-token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_ids' => ['USA-NY12345-X'],
        'sd_station_ids' => ['12345'],
        'sd_days_to_import' => 1,
    ]);

    // Mock responses with no artwork
    Http::fake([
        'json.schedulesdirect.org/20141201/metadata/stations' => Http::response([
            ['stationID' => '12345'],
        ]),
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response([
            'map' => [
                ['stationID' => '12345', 'channel' => '1.1'],
            ],
            'stations' => [
                ['stationID' => '12345', 'name' => 'Test Channel 1', 'callsign' => 'TEST1'],
            ],
        ]),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([
            [
                'stationID' => '12345',
                'programs' => [
                    [
                        'programID' => 'EP123',
                        'airDateTime' => '2025-09-18T20:00:00Z',
                        'duration' => 3600,
                    ],
                ],
            ],
        ]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([
            [
                'programID' => 'EP123',
                'titles' => [['title120' => 'Test Program']],
                'descriptions' => [
                    'description1000' => [['description' => 'Test description']],
                ],
            ],
        ]),
        'json.schedulesdirect.org/20141201/metadata/programs' => Http::response([
            ['programID' => 'EP123', 'data' => []],
        ]),
    ]);

    $service = new SchedulesDirectService;

    Storage::fake('local');

    $service->syncEpgData($epg);

    $xmlContent = Storage::disk('local')->get($epg->file_path);

    // Verify XMLTV is still generated without artwork
    expect($xmlContent)->toContain('<channel id="12345">');
    expect($xmlContent)->toContain('<programme channel="12345"');
    expect($xmlContent)->toContain('Test Program');

    // Verify no icon tags are present when no artwork available
    expect($xmlContent)->not->toContain('<icon');
});

/**
 * One SchedulesDirect /metadata/programs/ image entry.
 *
 * @return array<string, mixed>
 */
function sdImage(string $uri, string $category, string $aspect, int $width, int $height, string $tier = 'Series'): array
{
    return compact('uri', 'category', 'aspect', 'width', 'height', 'tier');
}

it('falls back to series artwork on the root programID when an episode has none of its own', function () {
    $requests = [];
    Http::fake(function ($request) use (&$requests) {
        $requests[] = $request->data();

        return Http::response(collect($request->data())->map(fn (string $programId) => match ($programId) {
            'EP017053304391' => ['programID' => $programId, 'data' => ['response' => 'INVALID_PROGRAMID', 'code' => 6000]],
            'EP01705330' => ['programID' => $programId, 'data' => [sdImage('series-wide.jpg', 'Iconic', '16x9', 960, 540)]],
            'EP000000010002' => ['programID' => $programId, 'data' => [sdImage('episode-wide.jpg', 'Iconic', '16x9', 960, 540, 'Episode')]],
        })->values()->all());
    });

    $artwork = (new SchedulesDirectService)->getProgramArtwork('token', ['EP017053304391', 'EP000000010002']);

    expect($requests)->toBe([['EP017053304391', 'EP000000010002'], ['EP01705330']])
        ->and($artwork['EP017053304391'][0]['url'])->toEndWith('/image/series-wide.jpg')
        ->and($artwork['EP000000010002'][0]['url'])->toEndWith('/image/episode-wide.jpg');
});

it('keeps the best 16:9 image alongside the higher resolution square and portrait picks', function () {
    Http::fake(fn ($request) => Http::response([[
        'programID' => 'SH012345670000',
        'data' => [
            sdImage('square-large.jpg', 'Iconic', '1x1', 2000, 2000),
            sdImage('square.jpg', 'Iconic', '1x1', 1400, 1400),
            sdImage('portrait.jpg', 'Iconic', '2x3', 480, 720),
            sdImage('wide-small.jpg', 'Iconic', '16x9', 480, 270),
            sdImage('wide.jpg', 'Iconic', '16x9', 960, 540),
            sdImage('banner-square.jpg', 'Banner-L1', '1x1', 2000, 2000),
            sdImage('banner-wide.jpg', 'Banner-L1', '16x9', 960, 540),
        ],
    ]]));

    $artwork = (new SchedulesDirectService)->getProgramArtwork('token', ['SH012345670000']);
    $urls = collect($artwork['SH012345670000'])->map(fn (array $image) => basename($image['url']))->all();

    expect($urls)->toBe(['square-large.jpg', 'square.jpg', 'banner-square.jpg', 'wide.jpg'])
        ->and($artwork['SH012345670000'][3])->toMatchArray(['type' => 'poster', 'width' => 960, 'height' => 540, 'orient' => 'L']);
});

it('adds no extra image when a 16:9 one is already among the picks', function () {
    Http::fake(fn ($request) => Http::response([[
        'programID' => 'SH012345670000',
        'data' => [
            sdImage('wide.jpg', 'Iconic', '16x9', 1920, 1080),
            sdImage('square.jpg', 'Iconic', '1x1', 1400, 1400),
        ],
    ]]));

    $artwork = (new SchedulesDirectService)->getProgramArtwork('token', ['SH012345670000']);

    expect(collect($artwork['SH012345670000'])->map(fn (array $image) => basename($image['url']))->all())
        ->toBe(['wide.jpg', 'square.jpg']);
});

it('serializes schedules direct artwork in xmltv dtd order with dimensioned legacy icons', function () {
    $service = new SchedulesDirectService;
    $method = new ReflectionMethod($service, 'writeProgramToXMLTV');
    $file = fopen('php://memory', 'w+');
    $programData = json_decode(json_encode([
        'programID' => 'EP012345670089',
        'entityType' => 'Episode',
        'titles' => [['title120' => 'Programme Art']],
        'descriptions' => ['description1000' => [['description' => 'Description']]],
        'genres' => ['Drama'],
        'metadata' => [['Gracenote' => ['season' => 1, 'episode' => 2]]],
        'contentRating' => [['country' => 'USA', 'body' => 'TVPG', 'code' => 'TV-14']],
    ], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    $method->invoke(
        $service,
        $file,
        'station-1',
        [
            'airDateTime' => '2026-09-30T10:00:00Z',
            'duration' => 3600,
            'new' => true,
        ],
        $programData,
        ['programs' => ['EP012345670089' => [
            ['url' => 'https://example.com/poster.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750],
            ['url' => 'https://example.com/backdrop.jpg', 'type' => 'backdrop', 'width' => 1280, 'height' => 720],
            ['url' => 'https://example.com/banner.jpg', 'type' => 'banner', 'width' => 1280, 'height' => 300],
        ]]],
    );

    rewind($file);
    $programmeXml = stream_get_contents($file);
    fclose($file);
    $dtd = realpath(base_path('tests/Fixtures/xmltv/xmltv.dtd'));
    $xml = '<?xml version="1.0"?><!DOCTYPE tv SYSTEM "file://'.$dtd.'"><tv>'.$programmeXml.'</tv>';
    $document = new DOMDocument;
    expect($document->loadXML($xml))->toBeTrue()
        ->and($document->validate())->toBeTrue();

    $xpath = new DOMXPath($document);
    $programme = $xpath->query('//programme')->item(0);
    $children = [];
    foreach ($programme->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $children[] = $child->tagName;
        }
    }

    expect($xpath->query('//programme/icon'))->toHaveCount(3)
        ->and($xpath->query('//programme/icon[@src="https://example.com/backdrop.jpg"][@width="1280"][@height="720"]'))->toHaveCount(1)
        ->and($xpath->query('//programme/icon[@src="https://example.com/poster.jpg"][@width="500"][@height="750"]'))->toHaveCount(1)
        ->and($xpath->query('//programme/icon[@src="https://example.com/banner.jpg"][@width="1280"][@height="300"]'))->toHaveCount(1)
        ->and($xpath->query('//programme/icon[@type or @orient or @size]'))->toHaveCount(0)
        ->and($xpath->query('//programme/episode-num[@system="m3u-editor:content-id"][text()="gracenote:EP012345670089"]'))->toHaveCount(1)
        ->and($xpath->query('//programme/episode-num[@system="m3u-editor:series-id"][text()="gracenote:SH012345670000"]'))->toHaveCount(1)
        ->and($xpath->query('//programme/image[@type="poster"][@orient="P"][@system="schedulesdirect"][text()="https://example.com/poster.jpg"]'))->toHaveCount(1)
        ->and($xpath->query('//programme/image[@type="backdrop"][@orient="L"][@system="schedulesdirect"][text()="https://example.com/backdrop.jpg"]'))->toHaveCount(1)
        ->and($xpath->query('//programme/image[contains(text(), "banner.jpg")]'))->toHaveCount(0)
        ->and($children)->toBe([
            'title',
            'desc',
            'category',
            'icon',
            'icon',
            'icon',
            'episode-num',
            'episode-num',
            'episode-num',
            'new',
            'rating',
            'image',
            'image',
        ]);
});
