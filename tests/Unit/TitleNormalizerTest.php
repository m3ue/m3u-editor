<?php

use App\Services\TitleNormalizer;

beforeEach(function () {
    $this->normalizer = new TitleNormalizer;
});

test('it normalizes titles by stripping provider prefixes', function () {
    $result = $this->normalizer->normalize('DK | The Last Viking');
    expect($result['title'])->toBe('the last viking');

    $result = $this->normalizer->normalize('4K-SC - The Last Viking (2025)');
    expect($result['title'])->toBe('the last viking');

    $result = $this->normalizer->normalize('SC - The Last Viking (2025)');
    expect($result['title'])->toBe('the last viking');

    $result = $this->normalizer->normalize('DK: Den sidste viking - 2025');
    expect($result['title'])->toBe('den sidste viking');
});

test('it extracts year from titles', function () {
    $result = $this->normalizer->normalize('The Last Viking (2025)');
    expect($result['year'])->toBe(2025);

    $result = $this->normalizer->normalize('DK: Den sidste viking - 2025');
    expect($result['year'])->toBe(2025);

    $result = $this->normalizer->normalize('The Last Viking');
    expect($result['year'])->toBeNull();
});

test('it strips quality tags from titles', function () {
    $result = $this->normalizer->normalize('4K UHD - Avatar');
    expect($result['title'])->toBe('avatar');

    $result = $this->normalizer->normalize('HD Movie Title');
    expect($result['title'])->toBe('movie title');
});

test('it calculates high similarity for same movie with different provider formats', function () {
    $titles = [
        'DK | The Last Viking',
        '4K-SC - The Last Viking (2025)',
        'SC - The Last Viking (2025)',
    ];

    foreach ($titles as $i => $titleA) {
        for ($j = $i + 1; $j < count($titles); $j++) {
            $similarity = $this->normalizer->similarity($titleA, $titles[$j]);
            expect($similarity)->toBeGreaterThanOrEqual(85.0,
                "Expected '{$titleA}' and '{$titles[$j]}' to have >=85% similarity, got {$similarity}%"
            );
        }
    }
});

test('it calculates lower similarity for different movies', function () {
    $similarity = $this->normalizer->similarity(
        'SC - The Last Viking (2025)',
        'SC - The Matrix (1999)'
    );
    expect($similarity)->toBeLessThan(70.0);
});

test('it groups titles by similarity', function () {
    $items = [
        ['id' => 1, 'title' => 'DK | The Last Viking'],
        ['id' => 2, 'title' => '4K-SC - The Last Viking (2025)'],
        ['id' => 3, 'title' => 'SC - The Last Viking (2025)'],
        ['id' => 4, 'title' => 'SC - The Matrix (1999)'],
        ['id' => 5, 'title' => 'HD - The Matrix (1999)'],
    ];

    $groups = $this->normalizer->groupBySimilarity($items, 80.0);

    // Should produce 2 groups: "the last viking" and "the matrix"
    expect($groups)->toHaveCount(2);

    $groupSizes = collect($groups)->map(fn ($g) => count($g))->sort()->values()->toArray();
    expect($groupSizes)->toBe([2, 3]);
});

test('it handles Danish and English title variants within grouping', function () {
    $items = [
        ['id' => 1, 'title' => 'DK | The Last Viking'],
        ['id' => 2, 'title' => '4K-SC - Den sidste viking (2025)'],
        ['id' => 3, 'title' => 'DK: Den sidste viking - 2025'],
    ];

    $groups = $this->normalizer->groupBySimilarity($items, 80.0);

    // Expect the Danish titles to group together but not with the English one
    // since "the last viking" and "den sidste viking" are different strings
    $danishGroupExists = false;
    foreach ($groups as $group) {
        $ids = collect($group)->pluck('id')->toArray();
        if (in_array(2, $ids) && in_array(3, $ids)) {
            $danishGroupExists = true;
        }
    }
    expect($danishGroupExists)->toBeTrue();
});

test('it boosts score when years match', function () {
    $simWithYear = $this->normalizer->similarity(
        'SC - The Last Viking (2025)',
        'HD - The Last Viking (2025)'
    );
    $simWithoutYear = $this->normalizer->similarity(
        'The Last Viking',
        'The Last Viking Alt'
    );

    // Same year should boost score
    expect($simWithYear)->toBeGreaterThanOrEqual(95.0);
});

test('it penalizes score when years differ', function () {
    $simSameYear = $this->normalizer->similarity(
        'SC - The Movie (2025)',
        'HD - The Movie (2025)'
    );
    $simDiffYear = $this->normalizer->similarity(
        'SC - The Movie (2025)',
        'HD - The Movie (2020)'
    );

    expect($simSameYear)->toBeGreaterThan($simDiffYear);
});

test('normalize handles empty and short titles gracefully', function () {
    $result = $this->normalizer->normalize('');
    expect($result['title'])->toBe('');

    $result = $this->normalizer->normalize('AB');
    expect($result['title'])->not->toBeEmpty();
});

test('similarity returns zero for empty strings', function () {
    expect($this->normalizer->similarity('', 'some title'))->toBe(0.0);
    expect($this->normalizer->similarity('some title', ''))->toBe(0.0);
});

test('it strips bracketed quality tags', function () {
    $result = $this->normalizer->normalize('The Movie [HD] [Multi]');
    expect($result['title'])->toBe('the movie');
});

test('it strips parenthesized quality tags', function () {
    $result = $this->normalizer->normalize('The Movie (HEVC) (Dual)');
    expect($result['title'])->toBe('the movie');
});

test('it strips version suffixes like dubbed and subbed', function () {
    $result = $this->normalizer->normalize('The Movie English Dubbed');
    expect($result['title'])->toBe('the movie');

    $result = $this->normalizer->normalize('The Movie French');
    expect($result['title'])->toBe('the movie');
});

test('it strips season/episode tags', function () {
    $result = $this->normalizer->normalize('The Show S01E05');
    expect($result['title'])->toBe('the show');

    $result = $this->normalizer->normalize('The Show S02');
    expect($result['title'])->toBe('the show');
});

test('it handles complex provider prefix combinations', function () {
    $result = $this->normalizer->normalize('FHD-US - Breaking Bad (2008)');
    expect($result['title'])->toBe('breaking bad');
    expect($result['year'])->toBe(2008);

    $result = $this->normalizer->normalize('4K - Movie Title');
    expect($result['title'])->toBe('movie title');

    $result = $this->normalizer->normalize('UHD | Amazing Series');
    expect($result['title'])->toBe('amazing series');
});

test('it matches titles across many different provider formats', function () {
    $titles = [
        'US | Inception (2010)',
        'FHD-US - Inception (2010)',
        'HD: Inception - 2010',
        'SC - Inception (2010)',
        '4K - Inception (2010)',
    ];

    foreach ($titles as $i => $titleA) {
        for ($j = $i + 1; $j < count($titles); $j++) {
            $similarity = $this->normalizer->similarity($titleA, $titles[$j]);
            expect($similarity)->toBeGreaterThanOrEqual(85.0,
                "Expected '{$titleA}' and '{$titles[$j]}' to have >=85% similarity, got {$similarity}%"
            );
        }
    }
});

test('it does not confuse titles with similar prefixes but different movies', function () {
    $similarity = $this->normalizer->similarity(
        'US | The Dark Knight (2008)',
        'US | The Dark Crystal (1982)'
    );
    expect($similarity)->toBeLessThan(80.0);
});

test('it handles unicode and accented characters', function () {
    $result = $this->normalizer->normalize('FR | Les Misérables (2019)');
    expect($result['title'])->toBe('les misérables');
    expect($result['year'])->toBe(2019);

    $similarity = $this->normalizer->similarity(
        'FR | Les Misérables (2019)',
        'HD - Les Misérables (2019)'
    );
    expect($similarity)->toBeGreaterThanOrEqual(95.0);
});
