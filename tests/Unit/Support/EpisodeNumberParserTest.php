<?php

use App\Support\EpisodeNumberParser;

describe('EpisodeNumberParser::fromRaw', function () {
    it('parses valid xmltv_ns dot notation (1-indexed conversion)', function (string $raw, ?int $season, ?int $episode) {
        expect(EpisodeNumberParser::fromRaw($raw))->toBe([$season, $episode]);
    })->with([
        'season + episode' => ['1.2.0/1', 2, 3],
        'season + episode no part' => ['1.2.', 2, 3],
        'zero indexed' => ['0.0.', 1, 1],
        'totals on each segment' => ['5.10/24.0/1', 6, 11],
        'empty season' => ['.5.', null, 6],
        'empty episode' => ['2..', 3, null],
    ]);

    it('parses SxxExx format (already 1-indexed)', function () {
        expect(EpisodeNumberParser::fromRaw('S01E03'))->toBe([1, 3]);
        expect(EpisodeNumberParser::fromRaw('s12e34'))->toBe([12, 34]);
    });

    it('rejects dd_progid and other non-xmltv_ns id strings', function (string $raw) {
        expect(EpisodeNumberParser::fromRaw($raw))->toBe([null, null]);
    })->with([
        'Schedules Direct EP id' => ['EP012345.0001'],
        'Schedules Direct SH id' => ['SH123.456'],
        'Schedules Direct MV id' => ['MV000123.0000'],
        'IMDb tt id with dot' => ['tt12345.0'],
        'opaque alpha tokens' => ['abc.def'],
        'just a dot' => ['.'],
        'trailing letters' => ['1.2foo.'],
        'leading letters' => ['x1.2.'],
    ]);

    it('returns null for empty / null input', function () {
        expect(EpisodeNumberParser::fromRaw(null))->toBe([null, null]);
        expect(EpisodeNumberParser::fromRaw(''))->toBe([null, null]);
        expect(EpisodeNumberParser::fromRaw('   '))->toBe([null, null]);
    });

    it('returns null for strings with no dot and no SxxExx', function () {
        expect(EpisodeNumberParser::fromRaw('Episode 5'))->toBe([null, null]);
        expect(EpisodeNumberParser::fromRaw('12345'))->toBe([null, null]);
    });
});

describe('EpisodeNumberParser::fromProgramme', function () {
    it('uses xmltv_ns when present and valid', function () {
        $programme = [
            'episode_num' => 'EP012345.0001',
            'episode_nums' => [
                ['system' => 'xmltv_ns', 'value' => '2.4.0/1'],
                ['system' => 'onscreen', 'value' => 'S99E99'],
            ],
        ];

        expect(EpisodeNumberParser::fromProgramme($programme))->toBe([3, 5]);
    });

    it('falls back to onscreen when xmltv_ns is invalid (e.g. dd_progid mis-tagged)', function () {
        $programme = [
            'episode_num' => 'SH123.456',
            'episode_nums' => [
                ['system' => 'xmltv_ns', 'value' => 'EP012345.0001'],
                ['system' => 'onscreen', 'value' => 'S03E07'],
            ],
        ];

        expect(EpisodeNumberParser::fromProgramme($programme))->toBe([3, 7]);
    });

    it('falls back to raw episode_num heuristic when no system tags match', function () {
        $programme = [
            'episode_num' => 'S04E11',
            'episode_nums' => [],
        ];

        expect(EpisodeNumberParser::fromProgramme($programme))->toBe([4, 11]);
    });

    it('returns null/null when only dd_progid IDs are available', function () {
        $programme = [
            'episode_num' => 'EP012345.0001',
            'episode_nums' => [
                ['system' => 'dd_progid', 'value' => 'EP012345.0001'],
            ],
        ];

        expect(EpisodeNumberParser::fromProgramme($programme))->toBe([null, null]);
    });
});

describe('EpisodeNumberParser::fromDescription', function () {
    it('parses "S01 E06 Landfall" prefix with episode title', function () {
        expect(EpisodeNumberParser::fromDescription("S01 E06 Landfall\nAfter a breakthrough..."))
            ->toBe([1, 6, 'Landfall']);
    });

    it('parses "S2E07" without title', function () {
        expect(EpisodeNumberParser::fromDescription('S2E07'))
            ->toBe([2, 7, null]);
    });

    it('parses "1x06 - Landfall" Plex/Trakt style', function () {
        expect(EpisodeNumberParser::fromDescription('1x06 - Landfall — synopsis'))
            ->toBe([1, 6, 'Landfall']);
    });

    it('parses "Season 1 Episode 6: Landfall" long form', function () {
        expect(EpisodeNumberParser::fromDescription('Season 1 Episode 6: Landfall. Synopsis.'))
            ->toBe([1, 6, 'Landfall']);
    });

    it('parses "Episode 6: Landfall" season-less', function () {
        expect(EpisodeNumberParser::fromDescription('Episode 6: Landfall'))
            ->toBe([null, 6, 'Landfall']);
    });

    it('parses "Ep. 6 - Landfall" abbreviated', function () {
        expect(EpisodeNumberParser::fromDescription('Ep. 6 - Landfall'))
            ->toBe([null, 6, 'Landfall']);
    });

    it('returns nulls for plain text without anchored prefix', function () {
        expect(EpisodeNumberParser::fromDescription('After Episode 6 things got weird.'))
            ->toBe([null, null, null]);
    });

    it('returns nulls for null input', function () {
        expect(EpisodeNumberParser::fromDescription(null))
            ->toBe([null, null, null]);
    });

    it('returns nulls for empty string', function () {
        expect(EpisodeNumberParser::fromDescription(''))
            ->toBe([null, null, null]);
    });

    it('rejects lowercase-starting title candidates', function () {
        expect(EpisodeNumberParser::fromDescription('S01E06 went terribly wrong'))
            ->toBe([1, 6, null]);  // season+episode extracted, title rejected
    });

    it('parses s1e6 lowercase format', function () {
        expect(EpisodeNumberParser::fromDescription('s1e6 The Pilot'))
            ->toBe([1, 6, 'The Pilot']);
    });
});
