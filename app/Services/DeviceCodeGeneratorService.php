<?php

namespace App\Services;

use App\Models\DeviceAuthorization;

class DeviceCodeGeneratorService
{
    /**
     * Unambiguous alphabet for human-entered codes: no 0/O or 1/I.
     */
    private const USER_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const USER_CODE_GROUP_LENGTH = 4;

    private const USER_CODE_GROUPS = 2;

    private const DEVICE_CODE_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private const DEVICE_CODE_LENGTH = 64;

    private const MAX_UNIQUENESS_ATTEMPTS = 10;

    /**
     * Generate a short, human-friendly pairing code, e.g. "XKQP-9F3T".
     */
    public static function generateUserCode(): string
    {
        for ($attempt = 0; $attempt < self::MAX_UNIQUENESS_ATTEMPTS; $attempt++) {
            $groups = [];
            for ($g = 0; $g < self::USER_CODE_GROUPS; $g++) {
                $groups[] = self::randomString(self::USER_CODE_ALPHABET, self::USER_CODE_GROUP_LENGTH);
            }

            $code = implode('-', $groups);

            if (! DeviceAuthorization::where('user_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate a unique device pairing code.');
    }

    /**
     * Generate a long, high-entropy device code. Never shown to a human.
     */
    public static function generateDeviceCode(): string
    {
        for ($attempt = 0; $attempt < self::MAX_UNIQUENESS_ATTEMPTS; $attempt++) {
            $code = self::randomString(self::DEVICE_CODE_ALPHABET, self::DEVICE_CODE_LENGTH);

            if (! DeviceAuthorization::where('device_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate a unique device code.');
    }

    private static function randomString(string $alphabet, int $length): string
    {
        $chars = [];
        for ($i = 0; $i < $length; $i++) {
            $chars[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return implode('', $chars);
    }
}
