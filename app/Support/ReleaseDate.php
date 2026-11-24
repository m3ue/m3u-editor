<?php

namespace App\Support;

class ReleaseDate
{
    /**
     * Reduce an ISO 8601 timestamp (Emby/Jellyfin "2018-12-06T00:00:00.0000000Z") to the
     * "YYYY-MM-DD" date Xtream clients expect. The date part is taken as-is, since a timezone
     * conversion could shift the day. Plain dates, bare years and free-form provider values are
     * returned untouched; the .NET "0001-01-01" unknown-date placeholder becomes null.
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})T\d{2}:/', $value, $matches) !== 1) {
            return $value;
        }

        return $matches[1] === '0001-01-01' ? null : $matches[1];
    }
}
