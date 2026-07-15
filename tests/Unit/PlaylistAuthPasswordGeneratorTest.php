<?php

use App\Support\PlaylistAuthPasswordGenerator;

it('generates passwords that meet the playlist auth rules', function (): void {
    foreach (range(1, 100) as $ignored) {
        $password = PlaylistAuthPasswordGenerator::generate();

        expect($password)->toHaveLength(10)
            ->and($password)->toMatch('/^[a-z0-9]+$/')
            ->and(PlaylistAuthPasswordGenerator::isValid($password))->toBeTrue();

        $letterCount = preg_match_all('/[a-z]/', $password);
        $numberCount = preg_match_all('/[0-9]/', $password);

        expect($letterCount)->toBeGreaterThanOrEqual(2)
            ->and($numberCount)->toBeGreaterThanOrEqual(2)
            ->and($letterCount >= 3 || $numberCount >= 3)->toBeTrue();
    }
});

it('respects a custom length while keeping the same character rules', function (): void {
    $password = PlaylistAuthPasswordGenerator::generate(16);

    expect($password)->toHaveLength(16)
        ->and(PlaylistAuthPasswordGenerator::isValid($password))->toBeTrue();
});

it('rejects passwords that break the character mix rules', function (string $password): void {
    expect(PlaylistAuthPasswordGenerator::isValid($password))->toBeFalse();
})->with([
    'too short' => ['abc12'],
    'uppercase letters' => ['abcdefghA1'],
    'special characters' => ['abcdefg!12'],
    'only one letter' => ['a123456789'],
    'only one number' => ['abcdefghij1'],
    'zero letters' => ['1234567890'],
    'zero numbers' => ['abcdefghij'],
]);
