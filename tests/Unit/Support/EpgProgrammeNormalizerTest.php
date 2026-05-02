<?php

use App\Support\EpgProgrammeNormalizer;

describe('EpgProgrammeNormalizer::normalizeTitle', function () {
    it('strips the ᴺᵉʷ superscript marker and flags is_new', function () {
        $result = EpgProgrammeNormalizer::normalizeTitle('Jimmy Kimmel Live!  ᴺᵉʷ');

        expect($result)->toBe([
            'title' => 'Jimmy Kimmel Live!',
            'isNew' => true,
        ]);
    });

    it('leaves a plain title untouched', function () {
        $result = EpgProgrammeNormalizer::normalizeTitle('Jimmy Kimmel Live!');

        expect($result)->toBe([
            'title' => 'Jimmy Kimmel Live!',
            'isNew' => false,
        ]);
    });

    it('handles the marker mid-string', function () {
        $result = EpgProgrammeNormalizer::normalizeTitle('Family Feud  ᴺᵉʷ Edition');

        expect($result['isNew'])->toBeTrue()
            ->and($result['title'])->toBe('Family Feud Edition');
    });

    it('collapses whitespace runs left by the strip', function () {
        $result = EpgProgrammeNormalizer::normalizeTitle('Show   ᴺᵉʷ');

        expect($result['title'])->toBe('Show');
    });

    it('returns empty for null or empty input', function () {
        expect(EpgProgrammeNormalizer::normalizeTitle(null))->toBe(['title' => '', 'isNew' => false]);
        expect(EpgProgrammeNormalizer::normalizeTitle(''))->toBe(['title' => '', 'isNew' => false]);
        expect(EpgProgrammeNormalizer::normalizeTitle('   '))->toBe(['title' => '', 'isNew' => false]);
    });
});

describe('EpgProgrammeNormalizer::extractSeasonEpisodeFromDescription', function () {
    it('extracts S/E with subtitle and description split by newline', function () {
        $desc = "S24 E110 Goldie Hawn; Asif Ali; Saagar Shaikh; Duran Duran; Nile Rodgers\n".
            'Actress Goldie Hawn; actors Asif Ali and Saagar Shaikh ("Deli Boys"); Duran Duran and Nile Rodgers perform.';

        $result = EpgProgrammeNormalizer::extractSeasonEpisodeFromDescription($desc);

        expect($result)->toBe([
            'season' => 24,
            'episode' => 110,
            'subtitle' => 'Goldie Hawn; Asif Ali; Saagar Shaikh; Duran Duran; Nile Rodgers',
            'description' => 'Actress Goldie Hawn; actors Asif Ali and Saagar Shaikh ("Deli Boys"); Duran Duran and Nile Rodgers perform.',
        ]);
    });

    it('extracts S/E and treats the remainder as description when no newline is present', function () {
        $desc = 'S14 E176 A candid look at how the gossip site operates.';

        $result = EpgProgrammeNormalizer::extractSeasonEpisodeFromDescription($desc);

        expect($result)->toBe([
            'season' => 14,
            'episode' => 176,
            'subtitle' => null,
            'description' => 'A candid look at how the gossip site operates.',
        ]);
    });

    it('handles the no-space SxxExx variant', function () {
        $desc = "S01E03 Pilot Episode\nFull description.";

        $result = EpgProgrammeNormalizer::extractSeasonEpisodeFromDescription($desc);

        expect($result['season'])->toBe(1)
            ->and($result['episode'])->toBe(3)
            ->and($result['subtitle'])->toBe('Pilot Episode')
            ->and($result['description'])->toBe('Full description.');
    });

    it('handles three-digit episode numbers', function () {
        $desc = 'S08 E101 The Big Show';

        $result = EpgProgrammeNormalizer::extractSeasonEpisodeFromDescription($desc);

        expect($result['season'])->toBe(8)
            ->and($result['episode'])->toBe(101);
    });

    it('returns null fields when no marker is present', function () {
        $desc = 'A brief description with no episode info.';

        $result = EpgProgrammeNormalizer::extractSeasonEpisodeFromDescription($desc);

        expect($result)->toBe([
            'season' => null,
            'episode' => null,
            'subtitle' => null,
            'description' => $desc,
        ]);
    });

    it('does not match an SxxExx marker that is not at the start', function () {
        $desc = 'A movie about S01 E03 of a fake show.';

        $result = EpgProgrammeNormalizer::extractSeasonEpisodeFromDescription($desc);

        expect($result['season'])->toBeNull()
            ->and($result['episode'])->toBeNull()
            ->and($result['description'])->toBe($desc);
    });

    it('returns nulls for null or empty input', function () {
        expect(EpgProgrammeNormalizer::extractSeasonEpisodeFromDescription(null))->toBe([
            'season' => null,
            'episode' => null,
            'subtitle' => null,
            'description' => null,
        ]);

        expect(EpgProgrammeNormalizer::extractSeasonEpisodeFromDescription(''))->toBe([
            'season' => null,
            'episode' => null,
            'subtitle' => null,
            'description' => '',
        ]);
    });

    it('handles leading whitespace before the marker', function () {
        $desc = "   S05 E12 Some Title\nDescription text.";

        $result = EpgProgrammeNormalizer::extractSeasonEpisodeFromDescription($desc);

        expect($result['season'])->toBe(5)
            ->and($result['episode'])->toBe(12)
            ->and($result['subtitle'])->toBe('Some Title')
            ->and($result['description'])->toBe('Description text.');
    });

    it('returns null subtitle when the line before newline is empty', function () {
        $desc = "S01 E01 \nJust a description.";

        $result = EpgProgrammeNormalizer::extractSeasonEpisodeFromDescription($desc);

        expect($result['subtitle'])->toBeNull()
            ->and($result['description'])->toBe('Just a description.');
    });

    it('clamps very large episode numbers to int16 max', function () {
        $desc = 'S99 E99999 Subtitle';

        $result = EpgProgrammeNormalizer::extractSeasonEpisodeFromDescription($desc);

        expect($result['episode'])->toBe(32767);
    });
});
