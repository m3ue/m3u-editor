<?php

namespace App\Support;

class PlaylistAuthPasswordGenerator
{
    private const LETTERS = 'abcdefghijklmnopqrstuvwxyz';

    private const NUMBERS = '0123456789';

    private const MIN_LENGTH = 10;

    private const MIN_PER_TYPE = 2;

    /**
     * Generate a password of lowercase letters and numbers only.
     *
     * Rules:
     * - At least 10 characters
     * - At least 2 letters and 2 numbers
     * - At least 3 letters or at least 3 numbers
     */
    public static function generate(int $length = self::MIN_LENGTH): string
    {
        $length = max($length, self::MIN_LENGTH);

        $letters = [];
        $numbers = [];

        for ($i = 0; $i < self::MIN_PER_TYPE; $i++) {
            $letters[] = self::LETTERS[random_int(0, strlen(self::LETTERS) - 1)];
            $numbers[] = self::NUMBERS[random_int(0, strlen(self::NUMBERS) - 1)];
        }

        // Ensure at least 3 of one type (letters or numbers).
        if (random_int(0, 1) === 0) {
            $letters[] = self::LETTERS[random_int(0, strlen(self::LETTERS) - 1)];
        } else {
            $numbers[] = self::NUMBERS[random_int(0, strlen(self::NUMBERS) - 1)];
        }

        $chars = [...$letters, ...$numbers];
        $alphabet = self::LETTERS.self::NUMBERS;

        while (count($chars) < $length) {
            $chars[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    public static function isValid(string $password): bool
    {
        if (strlen($password) < self::MIN_LENGTH) {
            return false;
        }

        if (preg_match('/[^a-z0-9]/', $password) === 1) {
            return false;
        }

        $letterCount = preg_match_all('/[a-z]/', $password);
        $numberCount = preg_match_all('/[0-9]/', $password);

        if ($letterCount < self::MIN_PER_TYPE || $numberCount < self::MIN_PER_TYPE) {
            return false;
        }

        return $letterCount >= 3 || $numberCount >= 3;
    }
}
