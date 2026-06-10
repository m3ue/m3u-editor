<?php

namespace App\Services;

class Utf8StringService
{
    public static function isValid(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
    }

    public static function clean(string $value): string
    {
        if (self::isValid($value)) {
            return $value;
        }

        $substituteCharacter = mb_substitute_character();
        mb_substitute_character('none');

        try {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($substituteCharacter);
        }
    }

    public static function preview(string $value, int $length = 120): string
    {
        return substr(self::clean($value), 0, $length);
    }
}
