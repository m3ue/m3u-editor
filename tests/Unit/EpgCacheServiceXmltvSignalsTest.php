<?php

use App\Services\EpgCacheService;

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

    $path = storage_path('framework/testing/epg-signals.xml.gz');
    $directory = dirname($path);
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    file_put_contents($path, gzencode($xml));

    $service = new EpgCacheService;
    $method = new ReflectionMethod($service, 'parseProgrammesStream');
    $method->setAccessible(true);

    $generator = $method->invoke($service, $path);
    $programmes = iterator_to_array($generator, false);

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

    @unlink($path);
});
