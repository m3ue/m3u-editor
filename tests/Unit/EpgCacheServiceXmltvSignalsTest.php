<?php

use App\Services\EpgCacheService;

// ---------------------------------------------------------------------------
// Helper: anonymous subclass that exposes parseProgrammesStream publicly so
// tests can call it without resorting to reflection.
// ---------------------------------------------------------------------------
function makeTestEpgCacheService(): EpgCacheService
{
    return new class extends EpgCacheService
    {
        public function exposeParseProgrammesStream(string $filePath): Generator
        {
            return $this->parseProgrammesStream($filePath);
        }
    };
}

// ---------------------------------------------------------------------------
// Fixture lifecycle: resolve the path inside the app context, not at load time.
// ---------------------------------------------------------------------------
beforeEach(function () {
    $this->testGzPath = storage_path('framework/testing/epg-signals.xml.gz');

    $directory = dirname($this->testGzPath);
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
});

afterEach(function () {
    if (file_exists($this->testGzPath)) {
        unlink($this->testGzPath);
    }
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('parses structural XMLTV signals into programme payloads', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <programme start="20260421080000 +0000" stop="20260421084500 +0000" channel="demo.channel">
    <title lang="de">SOKO Stuttgart</title>
    <sub-title lang="de">Auf Streife</sub-title>
    <desc>Test episode</desc>
    <category>Movie</category>
    <episode-num system="xmltv_ns">0.4.0/1</episode-num>
    <episode-num system="onscreen">S01E05</episode-num>
    <previously-shown />
    <premiere />
    <url system="imdb">https://www.imdb.com/title/tt0090390/</url>
    <url system="tvdb">https://thetvdb.com/series/alf</url>
    <date>2024</date>
  </programme>
</tv>
XML;

    file_put_contents($this->testGzPath, gzencode($xml));

    $service = makeTestEpgCacheService();
    $programmes = iterator_to_array($service->exposeParseProgrammesStream($this->testGzPath), false);

    expect($programmes)->toHaveCount(1);

    $programme = $programmes[0];

    expect($programme['title'])->toBe('SOKO Stuttgart')
        ->and($programme['subtitle'])->toBe('Auf Streife')
        ->and($programme['episode_num'])->toBe('0.4.0/1')
        ->and($programme['episode_nums'])->toBe([
            ['system' => 'xmltv_ns', 'value' => '0.4.0/1'],
            ['system' => 'onscreen', 'value' => 'S01E05'],
        ])
        ->and($programme['previously_shown'])->toBeTrue()
        ->and($programme['premiere'])->toBeTrue()
        ->and($programme['urls'])->toBe([
            ['system' => 'imdb', 'value' => 'https://www.imdb.com/title/tt0090390/'],
            ['system' => 'tvdb', 'value' => 'https://thetvdb.com/series/alf'],
        ])
        ->and($programme['production_year'])->toBe(2024);
});

it('rejects malformed or unsafe url values from epg feeds', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <programme start="20260421090000 +0000" stop="20260421093000 +0000" channel="demo.channel">
    <title>Test Programme</title>
    <url system="safe">https://www.imdb.com/title/tt0090390/</url>
    <url system="bad-scheme">javascript:alert(1)</url>
    <url system="not-a-url">not-a-url-at-all</url>
  </programme>
</tv>
XML;

    file_put_contents($this->testGzPath, gzencode($xml));

    $service = makeTestEpgCacheService();
    $programmes = iterator_to_array($service->exposeParseProgrammesStream($this->testGzPath), false);

    expect($programmes)->toHaveCount(1);

    // Only the valid HTTPS URL should be stored; javascript: and bare strings are rejected
    expect($programmes[0]['urls'])->toBe([
        ['system' => 'safe', 'value' => 'https://www.imdb.com/title/tt0090390/'],
    ]);
});

it('sets previously_shown and premiere to false by default', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <programme start="20260421100000 +0000" stop="20260421103000 +0000" channel="demo.channel">
    <title>Plain Programme</title>
  </programme>
</tv>
XML;

    file_put_contents($this->testGzPath, gzencode($xml));

    $service = makeTestEpgCacheService();
    $programmes = iterator_to_array($service->exposeParseProgrammesStream($this->testGzPath), false);

    expect($programmes)->toHaveCount(1)
        ->and($programmes[0]['previously_shown'])->toBeFalse()
        ->and($programmes[0]['premiere'])->toBeFalse()
        ->and($programmes[0]['urls'])->toBe([])
        ->and($programmes[0]['episode_nums'])->toBe([])
        ->and($programmes[0]['production_year'])->toBeNull();
});
