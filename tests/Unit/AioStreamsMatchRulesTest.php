<?php

use App\Services\AioStreamsQualityParser;

beforeEach(function () {
    $this->parser = new AioStreamsQualityParser;
});

/**
 * A stream shaped like real AIOStreams output: display text from the user's
 * formatter, the honest signals in behaviorHints.
 */
function aioMatchStream(string $filename, array $hints = [], string $name = 'AIOStreams', string $description = ''): array
{
    return [
        'name' => $name,
        'description' => $description,
        'url' => 'https://debrid.test/'.md5($filename),
        'behaviorHints' => ['filename' => $filename, ...$hints],
    ];
}

function aioRankedFilenames(array $ranked): array
{
    return array_map(fn (array $candidate) => $candidate['stream']['behaviorHints']['filename'], $ranked);
}

it('reads audio languages from the release filename, not the title', function (string $filename, array $expected) {
    expect($this->parser->parse(aioMatchStream($filename))['languages'])->toBe($expected);
})->with([
    'multi + french' => ['The.Odyssey.2026.MULTi.VFQ.2160p.HDR.BluRay.H265.mkv', ['multi', 'fr']],
    'dual audio' => ['A Odisseia 2026 WEB-DL 1080p x264 FULLHD DUAL 5.1.mkv', ['multi']],
    'spanish tag' => ['La odisea (The Asylum) (2026) [Bluray 720p][Esp].mkv', ['es']],
    'cyrillic dub release' => ['Одиссея / The Odyssey (2026) BDRip 1080p | D, P, P2, A | HDRezka Studio', ['ru']],
    'untagged original' => ['The.Odyssey.2026.1080p.WEB-DL.DDP5.1.H.264-WHO.mkv', []],
    'subtitle tag is not audio' => ['The.Odyssey.2026.1080p.Blu-ray.Remux.AVC-CiNEPHiLES.UKRsub.mkv', []],
    'language word in the title' => ['The.French.Dispatch.2021.1080p.BluRay.x264.mkv', []],
    'language word in an episode title' => ['Show.S02E05.The.Finnish.Line.1080p.WEB.h264-GRP.mkv', []],
    'episode language tag before the resolution' => ['Show.S01E01.FRENCH.1080p.WEB.mkv', ['fr']],
    'episode multi tags without a resolution' => ['Show.S01E01.MULTi.VFF.HDTV.x264.mkv', ['multi', 'fr']],
]);

it('reads the release source, preferring bingeGroup', function (string $filename, ?string $bingeGroup, ?string $expected) {
    $hints = $bingeGroup ? ['bingeGroup' => $bingeGroup] : [];

    expect($this->parser->parse(aioMatchStream($filename, $hints))['source'])->toBe($expected);
})->with([
    'remux from bingeGroup' => ['Some.Movie.2160p.mkv', 'aiostreams|2160p|BluRay REMUX|GRP', 'remux'],
    'bdrip' => ['The.Odyssey.2026.x264.BDRip.1080p.mkv', null, 'bluray'],
    'web-dl' => ['The Odyssey 2026 1080p AMZN WEB-DL DDP5 1 H 264-Kitsune.mkv', null, 'web-dl'],
    'webrip' => ['The.Odyssey.2026.1080p.WEBRip.x265-INFINITY.mp4', null, 'webrip'],
    'telesync' => ['New.Movie.2026.1080p.TS.x264.mkv', null, 'telesync'],
    'DTS audio is not telesync' => ['Movie.2026.1080p.BluRay.DTS-HD.MA.5.1.mkv', null, 'bluray'],
    'source word in a movie title' => ['Cam.2018.1080p.NF.WEB-DL.DDP5.1.x264-NTG.mkv', null, 'web-dl'],
    'title word is not a source' => ["Charlotte's.Web.2006.1080p.AMZN.DDP5.1.x264.mkv", null, null],
    'source word in an episode title' => ['Show.S01E03.The.Cam.1080p.WEB-DL.mkv', null, 'web-dl'],
    'bingeGroup beats the release name' => ['Cam.2018.1080p.x264.mkv', 'aiostreams|1080p|WEB-DL|NTG', 'web-dl'],
]);

it('falls back to the filename for a resolution the display text does not state', function () {
    expect($this->parser->parse(aioMatchStream('Movie.2026.2160p.WEB-DL.mkv'))['resolution'])->toBe(2160);
});

it('folds small-capital formatter text back to plain letters', function () {
    expect(AioStreamsQualityParser::normalizeText("» ʀᴇᴍᴜx ᴛ₁ \u{200D}\u{200D}"))->toBe('» remux t₁ ');
});

it('keeps the built-in ranking when no rules are set', function () {
    $streams = [
        aioMatchStream('Movie.720p.mkv', ['videoSize' => 1]),
        aioMatchStream('Movie.2160p.mkv', ['videoSize' => 1]),
        aioMatchStream('Movie.1080p.mkv', ['videoSize' => 1, 'cached' => true]),
    ];

    expect(aioRankedFilenames($this->parser->rank($streams)))
        ->toBe(['Movie.1080p.mkv', 'Movie.2160p.mkv', 'Movie.720p.mkv']);
});

it('orders by the preferred resolutions, then anything else highest first', function () {
    $streams = [
        aioMatchStream('Movie.2026.2160p.mkv'),
        aioMatchStream('Movie.2026.720p.mkv'),
        aioMatchStream('Movie.2026.1080p.mkv'),
        aioMatchStream('Movie.2026.480p.mkv'),
    ];

    expect(aioRankedFilenames($this->parser->rank($streams, ['resolutions' => ['1080', '2160']])))
        ->toBe(['Movie.2026.1080p.mkv', 'Movie.2026.2160p.mkv', 'Movie.2026.720p.mkv', 'Movie.2026.480p.mkv'])
        ->and(aioRankedFilenames($this->parser->rank($streams, ['resolutions' => [1080], 'only_listed_resolutions' => true])))
        ->toBe(['Movie.2026.1080p.mkv']);
});

it('ranks language before resolution and can require a preferred language', function () {
    $streams = [
        aioMatchStream('Odyssey.2026.2160p.Remux.mkv', ['videoSize' => 90_000_000_000]),
        aioMatchStream('Odyssey.2026.MULTi.VFQ.1080p.WEB-DL.mkv'),
        aioMatchStream('Одиссея (2026) 2160p BDRemux | D, P, A'),
    ];

    // French viewer: French first, then the original-language releases.
    expect(aioRankedFilenames($this->parser->rank($streams, ['languages' => ['fr', 'original']])))
        ->toBe(['Odyssey.2026.MULTi.VFQ.1080p.WEB-DL.mkv', 'Odyssey.2026.2160p.Remux.mkv', 'Одиссея (2026) 2160p BDRemux | D, P, A']);

    // English viewer who never wants dubs: untagged and multi-audio carry the original track.
    expect(aioRankedFilenames($this->parser->rank($streams, ['languages' => ['en', 'original'], 'require_language' => true])))
        ->toBe(['Odyssey.2026.2160p.Remux.mkv', 'Odyssey.2026.MULTi.VFQ.1080p.WEB-DL.mkv']);
});

it('explains why each stream was excluded', function () {
    $streams = [
        aioMatchStream('Movie.2026.1080p.WEB-DL.mkv', ['videoSize' => 4 * 1_073_741_824]),
        aioMatchStream('Movie.2026.2160p.Remux.mkv', ['videoSize' => 80 * 1_073_741_824]),
        aioMatchStream('Movie.2026.1080p.HDCAM.mkv', ['videoSize' => 2 * 1_073_741_824]),
        aioMatchStream('Movie.2026.1080p.Dubbed.WEB-DL.mkv', ['videoSize' => 3 * 1_073_741_824]),
    ];

    $explained = $this->parser->explain($streams, [
        'max_size_gb' => '60',
        'excluded_sources' => ['cam'],
        'excluded_keywords' => ['dubbed'],
    ]);

    $reasons = collect($explained)->mapWithKeys(fn (array $c) => [$c['stream']['behaviorHints']['filename'] => $c['excluded']])->all();

    expect($explained[0]['excluded'])->toBeNull()
        ->and($reasons)->toBe([
            'Movie.2026.1080p.WEB-DL.mkv' => null,
            'Movie.2026.2160p.Remux.mkv' => 'Too large',
            'Movie.2026.1080p.Dubbed.WEB-DL.mkv' => 'Keyword: dubbed',
            'Movie.2026.1080p.HDCAM.mkv' => 'Source',
        ])
        ->and($this->parser->rank($streams, ['max_size_gb' => '60', 'excluded_sources' => ['cam'], 'excluded_keywords' => ['dubbed']]))->toHaveCount(1);
});

it('matches keywords as whole words across formatter text and the filename', function () {
    $streams = [
        aioMatchStream('Movie.2026.1080p.BluRay.DTS-HD.mkv', ['videoSize' => 2]),
        aioMatchStream('Movie.2026.1080p.BluRay.x264.mkv', ['videoSize' => 1], 'AIOStreams', '» ʀᴇᴍᴜx'),
    ];

    expect($this->parser->rank($streams, ['excluded_keywords' => ['TS']]))->toHaveCount(2)
        ->and(aioRankedFilenames($this->parser->rank($streams, ['preferred_keywords' => ['remux']])))
        ->toBe(['Movie.2026.1080p.BluRay.x264.mkv', 'Movie.2026.1080p.BluRay.DTS-HD.mkv']);
});

it('can prefer smaller files as the final tiebreak', function () {
    $streams = [
        aioMatchStream('Movie.2026.1080p.A.mkv', ['videoSize' => 40 * 1_073_741_824]),
        aioMatchStream('Movie.2026.1080p.B.mkv', ['videoSize' => 4 * 1_073_741_824]),
    ];

    expect(aioRankedFilenames($this->parser->rank($streams, ['prefer_smaller' => true])))
        ->toBe(['Movie.2026.1080p.B.mkv', 'Movie.2026.1080p.A.mkv']);
});

it('drops every hard filter but keeps the ordering for the best-available fallback', function () {
    $rules = ['languages' => ['fr'], 'require_language' => true, 'max_size_gb' => 1, 'excluded_keywords' => ['x']];

    expect($this->parser->orderingOnly($rules))->toMatchArray(['languages' => ['fr']])
        ->and($this->parser->orderingOnly($rules))->not->toHaveKeys(['require_language', 'max_size_gb', 'excluded_keywords']);
});

it('describes the parsed tags in one line', function () {
    $parsed = $this->parser->parse(aioMatchStream('The.Odyssey.2026.MULTi.VFQ.2160p.HDR.BluRay.H265-GRP.mkv', ['videoSize' => 18 * 1_073_741_824]));

    expect(AioStreamsQualityParser::describe($parsed))
        ->toContain('2160p')
        ->toContain('BluRay')
        ->toContain('Audio: multi, fr')
        ->toContain('18 GB')
        ->toContain('GRP');
});
