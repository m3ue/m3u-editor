<?php

use App\Models\Channel;
use App\Services\VersionDetectionService;

it('detects edition tags from movie titles', function (string $title, ?string $expected) {
    expect((new VersionDetectionService)->detectEditionFromTitle($title))->toBe($expected);
})->with([
    'The Matrix Reloaded (Extended)' => ['The Matrix Reloaded (Extended)', 'Extended'],
    'Blade Runner (Director\'s Cut)' => ["Blade Runner (Director's Cut)", "Director's Cut"],
    'Movie (IMAX)' => ['Movie (IMAX)', 'IMAX'],
    'Regular Movie' => ['Regular Movie', null],
    'Unrated Edition' => ['Unrated Edition', 'Unrated'],
]);

it('detects edition with custom regex pattern', function () {
    $service = new VersionDetectionService;

    $edition = $service->detectEditionWithPattern('Movie [4K Remaster]', '/\[([^\]]+)\]/');

    expect($edition)->toBe('4K Remaster');
});

it('returns null for empty pattern', function () {
    expect((new VersionDetectionService)->detectEditionWithPattern('Title', ''))->toBeNull();
});

it('detects edition from channel model', function () {
    $channel = new Channel(['name' => 'Dune (IMAX)']);

    expect((new VersionDetectionService)->detectEdition($channel))->toBe('IMAX');
});

it('uses custom pattern over built-in tags', function () {
    $channel = new Channel(['name' => 'Movie [Special Release]']);

    $edition = (new VersionDetectionService)->detectEdition($channel, '/\[([^\]]+)\]/');

    expect($edition)->toBe('Special Release');
});

it('extracts base title by removing edition tags', function (string $title, string $expected) {
    expect((new VersionDetectionService)->extractBaseTitle($title))->toBe($expected);
})->with([
    'Blade Runner (Final Cut)' => ['Blade Runner (Final Cut)', 'Blade Runner'],
    'Movie - Extended Cut' => ['Movie - Extended Cut', 'Movie'],
    'Film [Remastered]' => ['Film [Remastered]', 'Film'],
    'Simple Title' => ['Simple Title', 'Simple Title'],
]);

it('compares two channels as same movie', function () {
    $service = new VersionDetectionService;
    $a = new Channel(['name' => 'Blade Runner (Final Cut)']);
    $b = new Channel(['name' => 'Blade Runner (Directors Cut)']);

    expect($service->areVersionsOfSameMovie($a, $b))->toBeTrue();
});

it('rejects different movies', function () {
    $service = new VersionDetectionService;
    $a = new Channel(['name' => 'The Matrix']);
    $b = new Channel(['name' => 'The Matrix Reloaded']);

    expect($service->areVersionsOfSameMovie($a, $b))->toBeFalse();
});

it('groups channels by base title', function () {
    $channels = [
        new Channel(['name' => 'Blade Runner (Final Cut)']),
        new Channel(['name' => 'Blade Runner (Directors Cut)']),
        new Channel(['name' => 'Dune (IMAX)']),
        new Channel(['name' => 'Dune (Extended)']),
    ];

    $groups = (new VersionDetectionService)->groupByMovie($channels);

    expect($groups)->toHaveCount(2)
        ->and(array_values($groups)[0])->toHaveCount(2)
        ->and(array_values($groups)[1])->toHaveCount(2);
});

it('skips empty titles when grouping', function () {
    $channels = [
        new Channel(['name' => '']),
        new Channel(['name' => 'Valid Movie']),
    ];

    $groups = (new VersionDetectionService)->groupByMovie($channels);

    expect($groups)->toHaveCount(1);
});
