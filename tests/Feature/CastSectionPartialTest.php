<?php

use App\Filament\Pages\ActorFilmography;

it('renders nothing when cast is empty', function () {
    $html = view('filament.partials.cast-section', [
        'cast' => [],
    ])->render();

    expect(trim($html))->toBe('');
});

it('renders one avatar tile per cast member', function () {
    $html = view('filament.partials.cast-section', [
        'cast' => [
            ['id' => 1, 'name' => 'Alice', 'character' => 'Lead', 'photo' => 'https://example.com/a.jpg'],
            ['id' => 2, 'name' => 'Bob', 'character' => null, 'photo' => null],
        ],
    ])->render();

    expect(substr_count($html, 'fi-avatar fi-circular fi-size-lg'))->toBe(2);
    expect($html)->toContain('Alice');
    expect($html)->toContain('Lead');
    expect($html)->toContain('Bob');
    expect($html)->toContain('example.com/a.jpg');
});

it('wraps each tile in a link to ActorFilmography when filmographyPage is provided', function () {
    $html = view('filament.partials.cast-section', [
        'cast' => [
            ['id' => 42, 'name' => 'Nathan Phillips', 'character' => 'Morgan', 'photo' => 'https://example.com/n.jpg'],
        ],
        'filmographyPage' => ActorFilmography::class,
        'playlistId' => 7,
    ])->render();

    expect($html)->toMatch('/<a[^>]*href="[^"]*personId=42[^"]*"[^>]*>/');
    expect($html)->toMatch('/<a[^>]*href="[^"]*playlistId=7[^"]*"/');
    expect($html)->toContain('View filmography');
});

it('skips the link wrapper when filmographyPage is not provided (static display)', function () {
    $html = view('filament.partials.cast-section', [
        'cast' => [
            ['id' => 1, 'name' => 'Alice', 'character' => 'Lead', 'photo' => null],
        ],
    ])->render();

    expect(substr_count($html, '<a '))->toBe(0);
    expect($html)->toContain('Alice');
});
