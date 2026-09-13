<?php

use App\Filament\Pages\ActorFilmography;

it('renders nothing when castMembers is empty', function () {
    $html = view('filament.partials.cast-avatar-grid', [
        'castMembers' => [],
        'filmographyPage' => ActorFilmography::class,
    ])->render();

    expect($html)->toBe('');
});

it('renders one avatar tile per cast member with a filmography link', function () {
    $html = view('filament.partials.cast-avatar-grid', [
        'castMembers' => [
            [
                'id' => 42,
                'actor' => 'Nathan Phillips',
                'character' => 'Morgan',
                'photo' => 'https://image.tmdb.org/t/p/w185/abc.jpg',
            ],
            [
                'id' => 99,
                'actor' => 'No Photo Actor',
                'character' => '',
                'photo' => null,
            ],
        ],
        'filmographyPage' => ActorFilmography::class,
        'playlistId' => 7,
    ])->render();

    // One tile per member. The avatar class is emitted by <x-filament::avatar>
    // ONLY for the member that has a photo — total count is 1 in this fixture.
    expect(substr_count($html, 'fi-avatar fi-circular fi-size-lg'))->toBe(1);

    // User's reference HTML: link container is `flex w-24 flex-shrink-0 flex-col items-center text-center`
    expect(substr_count($html, 'flex w-24 flex-shrink-0 flex-col items-center text-center'))->toBe(2);

    // The avatar <img> is direct child of the <a> — no wrapping div around the img.
    // Verify by checking that the substring immediately before the avatar classes is
    // the <a> tag, not a <div>.
    expect($html)->toMatch('/<a[^>]*>\s*<img[^>]*fi-avatar fi-circular fi-size-lg/');

    // First member: name, character, photo src, and a link to the filmography page
    expect($html)->toContain('Nathan Phillips');
    expect($html)->toContain('Morgan');
    expect($html)->toContain('abc.jpg');

    // Filmography URL contains personId, name, and playlistId (URL-encoded with %20 or +)
    expect($html)->toMatch('/personId=42/');
    expect($html)->toMatch('/name=Nathan(\+|%20)Phillips/');
    expect($html)->toMatch('/playlistId=7/');

    // Name + character use the exact classes from the reference HTML
    expect($html)->toContain('mt-2 line-clamp-2 text-sm font-medium text-gray-900 dark:text-gray-100');
    expect($html)->toContain('line-clamp-2 text-xs text-gray-500 dark:text-gray-400');

    // Second member without photo falls back to a styled placeholder div with
    // a user icon, not an <x-filament::avatar> element (the literal string
    // `heroicon-o-user` won't appear because the component renders inline SVG).
    expect($html)->toContain('No Photo Actor');
    expect($html)->toContain('rounded-full bg-gray-200 dark:bg-gray-700');
    expect($html)->toMatch('/<svg[^>]*>/');
});

it('omits playlistId from the URL when not provided', function () {
    $html = view('filament.partials.cast-avatar-grid', [
        'castMembers' => [
            ['id' => 5, 'actor' => 'Solo', 'character' => '', 'photo' => null],
        ],
        'filmographyPage' => ActorFilmography::class,
    ])->render();

    expect($html)->not->toContain('playlistId=');
});
