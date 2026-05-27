<?php

use App\Services\TorrentTitleParser;

beforeEach(function () {
    $this->parser = new TorrentTitleParser;
});

// -------------------------------------------------------------------------
// Episodes (S01E01 / NxNN)
// -------------------------------------------------------------------------

it('parses standard SxxExx episode filename', function (string $input, string $title, int $season, int $episode) {
    $result = $this->parser->parse($input);

    expect($result)->toMatchArray([
        'title' => $title,
        'season' => $season,
        'episode' => $episode,
        'is_episode' => true,
        'is_pack' => false,
    ]);
})->with([
    'dot-separated' => ['Survivor.S50E06.1080p.WEB-DL.mkv', 'Survivor', 50, 6],
    'space-separated' => ['Show Name S01E01 - Episode Title.mkv', 'Show Name', 1, 1],
    'lower case markers' => ['show.name.s02e10.720p.mkv', 'show name', 2, 10],
    'three-digit episode' => ['Show.S01E101.mkv', 'Show', 1, 101],
    'no space between S/E' => ['ShowName.S03E04.mkv', 'ShowName', 3, 4],
]);

it('parses NxNN episode format', function () {
    $result = $this->parser->parse('Show.Name.2x05.720p.mkv');

    expect($result)->toMatchArray([
        'title' => 'Show Name',
        'season' => 2,
        'episode' => 5,
        'is_episode' => true,
        'is_pack' => false,
    ]);
});

it('parses dot-separated S03.E01 episode format', function () {
    $result = $this->parser->parse('Euphoria.US.S03.E01.1080p.WEB-DL.mkv');

    expect($result)->toMatchArray([
        'title' => 'Euphoria US',
        'season' => 3,
        'episode' => 1,
        'is_episode' => true,
        'is_pack' => false,
        'is_multi_season_pack' => false,
    ]);
});

// -------------------------------------------------------------------------
// Season packs
// -------------------------------------------------------------------------

it('parses multi-season pack with range', function () {
    $result = $this->parser->parse('Formula.1.S01-08.MULTi.1080p.WEB-DL');

    expect($result)->toMatchArray([
        'season' => 1,
        'is_episode' => false,
        'is_pack' => true,
        'is_multi_season_pack' => true,
    ]);
});

it('parses multi-season pack with bracket range', function () {
    $result = $this->parser->parse('Show Name 2019-2025 [S01-S07] [10Bit HDR][1080p NF WEB-DL H265][Lektor PL][Group]');

    expect($result)->toMatchArray([
        'season' => 1,
        'is_episode' => false,
        'is_pack' => true,
        'is_multi_season_pack' => true,
    ]);
});

it('parses standalone season pack keyword', function (string $input, int $season) {
    $result = $this->parser->parse($input);

    expect($result)->toMatchArray([
        'season' => $season,
        'is_episode' => false,
        'is_pack' => true,
        'is_multi_season_pack' => false,
    ]);
})->with([
    'Season keyword dot-separated' => ['Man.on.Fire.Season.1.MULTi.1080p', 1],
    'Season keyword space-separated' => ['Man on Fire Season 2 MULTi', 2],
    'Saison keyword dot-separated' => ['Show.Saison.2.FRENCH', 2],
    'S-only marker' => ['Man.on.Fire.S01.MULTi.1080p', 1],
]);

it('parses season pack directory with year and quality suffix (S01)', function () {
    $result = $this->parser->parse('Georgie & Mandy\'s First Marriage (2024) S01 (1080p AMZN WEB-DL x265 10bit EAC3 5.1 Ghost)');

    expect($result)->toMatchArray([
        'title' => "Georgie & Mandy's First Marriage",
        'year' => 2024,
        'season' => 1,
        'is_episode' => false,
        'is_pack' => true,
        'is_multi_season_pack' => false,
    ]);
});

it('parses season pack directory with dot-separated name (S02)', function () {
    $result = $this->parser->parse('Georgie.and.Mandys.First.Marriage.S02.1080p.x265-ELiTE');

    expect($result)->toMatchArray([
        'season' => 2,
        'is_episode' => false,
        'is_pack' => true,
        'is_multi_season_pack' => false,
    ]);
});

it('normalises differently-formatted S01 and S02 pack directory names to the same show', function () {
    $s01 = $this->parser->parse('Georgie & Mandy\'s First Marriage (2024) S01 (1080p AMZN WEB-DL x265 10bit EAC3 5.1 Ghost)');
    $s02 = $this->parser->parse('Georgie.and.Mandys.First.Marriage.S02.1080p.x265-ELiTE');

    // Both must parse as single-season packs
    expect($s01['is_pack'])->toBeTrue()
        ->and($s01['is_multi_season_pack'])->toBeFalse()
        ->and($s02['is_pack'])->toBeTrue()
        ->and($s02['is_multi_season_pack'])->toBeFalse();

    // The normalised key used for grouping must be identical
    $normalize = function (string $title): string {
        $title = mb_strtolower($title);
        $title = str_replace('&', 'and', $title);
        $title = preg_replace("/['\x{2019}\x{2018}]/u", '', $title) ?? $title;
        $title = preg_replace('/[^\p{L}\p{N} ]/u', ' ', $title) ?? $title;
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;

        return trim($title);
    };

    expect($normalize($s01['title']))->toBe($normalize($s02['title']));
});

// -------------------------------------------------------------------------
// Movies / standalone items
// -------------------------------------------------------------------------

it('parses a standard movie filename', function () {
    $result = $this->parser->parse('The.Dark.Knight.2008.4K.UHD.BluRay.mkv');

    expect($result)->toMatchArray([
        'title' => 'The Dark Knight',
        'year' => 2008,
        'is_episode' => false,
        'is_pack' => false,
    ]);
});

it('preserves decimal points in titles like Formula 1', function () {
    $result = $this->parser->parse('Formula.1.Drive.to.Survive.S01E01.mkv');

    expect($result['title'])->toBe('Formula 1 Drive to Survive');
});

it('returns null year when no year is present', function () {
    $result = $this->parser->parse('SomeMovie.BluRay.mkv');

    expect($result['year'])->toBeNull();
});

it('extracts year from movie filename', function () {
    $result = $this->parser->parse('Remarkably.Bright.Creatures.2024.1080p.mkv');

    expect($result['year'])->toBe(2024);
});

// -------------------------------------------------------------------------
// Site watermark stripping
// -------------------------------------------------------------------------

it('strips leading bracket site watermark', function () {
    $result = $this->parser->parse('[SOMESITE.ORG] Show.Name.S01E01.mkv');

    expect($result['is_episode'])->toBeTrue()
        ->and($result['title'])->not->toContain('SOMESITE');
});

it('strips www.domain.tld watermark with dash separator', function () {
    $result = $this->parser->parse('www.SiteName.org    -    Show.Name.S02E05.720p.mkv');

    expect($result['is_episode'])->toBeTrue()
        ->and($result['title'])->not->toContain('SiteName');
});

// -------------------------------------------------------------------------
// Directory names (no file extension)
// -------------------------------------------------------------------------

it('parses an episode container directory name', function () {
    $result = $this->parser->parse('Show Name S01E07 [Group]');

    expect($result)->toMatchArray([
        'season' => 1,
        'episode' => 7,
        'is_episode' => true,
        'is_pack' => false,
    ]);
});

it('parses a season pack directory name', function () {
    $result = $this->parser->parse('Show.Name.2026.S01.MULTi');

    expect($result)->toMatchArray([
        'season' => 1,
        'is_episode' => false,
        'is_pack' => true,
    ]);
});

it('does not treat a www.domain directory name as having a file extension', function () {
    $result = $this->parser->parse('www.UIndex.org    -    Show Name S01E01 [Group]');

    expect($result['is_episode'])->toBeTrue();
});

// -------------------------------------------------------------------------
// Title cleanup edge cases
// -------------------------------------------------------------------------

it('strips trailing year range from title', function () {
    $result = $this->parser->parse('Some.Show.2019-2025.S01.MULTi');

    expect($result['title'])->not->toContain('2019');
});

it('strips trailing standalone year from title', function () {
    $result = $this->parser->parse('The.Dark.Knight.2008.BluRay.mkv');

    expect($result['title'])->toBe('The Dark Knight');
});

it('produces a clean title from a heavily tagged release', function () {
    $result = $this->parser->parse('Show.Name.S01E01.1080p.BluRay.x264-GROUP');

    expect($result['title'])->toBe('Show Name');
});
