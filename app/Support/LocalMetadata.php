<?php

namespace App\Support;

/**
 * Merges a provider refresh into metadata already stored on a row (VOD `info`, series
 * `metadata`, episode `info`), for the cases where the stored values should survive it.
 */
class LocalMetadata
{
    /**
     * Stored values win: every non-blank stored key is kept over the provider's value,
     * and the provider only fills keys that are blank or missing locally. Used when a
     * playlist makes its local metadata authoritative (preserve_*_metadata).
     *
     * @param  list<string>  $providerOwnedKeys  Keys that always take the provider's value (e.g. last_modified).
     */
    public static function keepLocal(mixed $existing, ?array $incoming, array $providerOwnedKeys = []): ?array
    {
        $existing = self::toArray($existing);

        if ($existing === []) {
            return $incoming;
        }

        // The provider sent nothing this time: keep what is stored rather than clearing it.
        if ($incoming === null) {
            return $existing;
        }

        foreach ($existing as $key => $value) {
            if (! blank($value) && ! in_array($key, $providerOwnedKeys, true)) {
                $incoming[$key] = $value;
            }
        }

        return $incoming;
    }

    /**
     * Provider values win, but a blank or missing provider value doesn't wipe a stored one.
     */
    public static function fillBlanks(mixed $existing, ?array $incoming): ?array
    {
        $existing = self::toArray($existing);

        if ($incoming === null || $existing === []) {
            return $incoming;
        }

        foreach ($existing as $key => $value) {
            if (! blank($value) && blank($incoming[$key] ?? null)) {
                $incoming[$key] = $value;
            }
        }

        return $incoming;
    }

    /**
     * Persisted metadata is normally cast to an array, but legacy rows can hold a
     * JSON-encoded string instead.
     *
     * @return array<string, mixed>
     */
    private static function toArray(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
