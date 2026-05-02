<?php

use App\Services\EpgCacheService;

// ---------------------------------------------------------------------------
// Helper: anonymous subclass that exposes internal methods for testing.
// ---------------------------------------------------------------------------
function makeTestEpgCacheService(): EpgCacheService
{
    return new class extends EpgCacheService
    {
        public function exposeParseProgrammesStream(string $filePath): Generator
        {
            return $this->parseProgrammesStream($filePath);
        }

        /** @return array{0: int|null, 1: int|null} */
        public function exposeParseEpisodeNumbers(array $programme): array
        {
            return $this->parseEpisodeNumbers($programme);
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
// parseProgrammesStream — structural parsing tests
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

// ---------------------------------------------------------------------------
// parseEpisodeNumbers — episode number parsing tests
// ---------------------------------------------------------------------------

function makeEpisodeProgramme(array $episodeNums, string $episodeNum = ''): array
{
    return [
        'episode_num' => $episodeNum ?: ($episodeNums[0]['value'] ?? ''),
        'episode_nums' => $episodeNums,
    ];
}

it('parses xmltv_ns dots format (0-indexed) from explicit system tag', function () {
    $service = makeTestEpgCacheService();

    // "1.2." → season 2, episode 3  (0-indexed)
    [$season, $episode] = $service->exposeParseEpisodeNumbers(makeEpisodeProgramme(
        [['system' => 'xmltv_ns', 'value' => '1.2.']]
    ));

    expect($season)->toBe(2)->and($episode)->toBe(3);
});

it('parses onscreen SxxExx format (1-indexed) from explicit system tag', function () {
    $service = makeTestEpgCacheService();

    [$season, $episode] = $service->exposeParseEpisodeNumbers(makeEpisodeProgramme(
        [['system' => 'onscreen', 'value' => 'S02E05']]
    ));

    expect($season)->toBe(2)->and($episode)->toBe(5);
});

it('prefers xmltv_ns over onscreen when both system tags are present', function () {
    $service = makeTestEpgCacheService();

    // xmltv_ns "0.4." → S1E5; onscreen says S01E05 — both agree in 1-indexed terms
    // but the xmltv_ns one is authoritative and should be chosen
    [$season, $episode] = $service->exposeParseEpisodeNumbers(makeEpisodeProgramme(
        [
            ['system' => 'xmltv_ns', 'value' => '0.4.'],
            ['system' => 'onscreen', 'value' => 'S02E10'],
        ],
        '0.4.'
    ));

    expect($season)->toBe(1)->and($episode)->toBe(5);
});

it('falls back to heuristic xmltv_ns dots parsing when no system tag is set', function () {
    $service = makeTestEpgCacheService();

    [$season, $episode] = $service->exposeParseEpisodeNumbers(makeEpisodeProgramme(
        [['system' => '', 'value' => '2.7.']],
        '2.7.'
    ));

    expect($season)->toBe(3)->and($episode)->toBe(8);
});

it('falls back to heuristic onscreen parsing when no system tag is set', function () {
    $service = makeTestEpgCacheService();

    [$season, $episode] = $service->exposeParseEpisodeNumbers(makeEpisodeProgramme(
        [['system' => '', 'value' => 'S03E12']],
        'S03E12'
    ));

    expect($season)->toBe(3)->and($episode)->toBe(12);
});

it('returns null for both season and episode when episode_num is absent', function () {
    $service = makeTestEpgCacheService();

    [$season, $episode] = $service->exposeParseEpisodeNumbers([
        'episode_num' => '',
        'episode_nums' => [],
    ]);

    expect($season)->toBeNull()->and($episode)->toBeNull();
});

it('parses season-only xmltv_ns entry leaving episode null', function () {
    $service = makeTestEpgCacheService();

    // ".5." — empty season part, episode=6
    [$season, $episode] = $service->exposeParseEpisodeNumbers(makeEpisodeProgramme(
        [['system' => 'xmltv_ns', 'value' => '.5.']],
        '.5.'
    ));

    expect($season)->toBeNull()->and($episode)->toBe(6);
});
