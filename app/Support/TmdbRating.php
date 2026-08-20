<?php

namespace App\Support;

use App\Settings\GeneralSettings;

/**
 * Centralizes the "suppress ratings backed by too few TMDB votes" rule so
 * every consumer (Xtream API, admin/guest Filament views) applies the exact
 * same threshold and null-handling semantics.
 */
class TmdbRating
{
    public static function isVoteCountBelowThreshold(mixed $voteCount): bool
    {
        if ($voteCount === null) {
            return false;
        }

        $minVoteCount = app(GeneralSettings::class)->tmdb_min_vote_count ?? 25;

        return $voteCount < $minVoteCount;
    }

    /**
     * Returns $rating unless the given vote count is below the configured
     * minimum, in which case $suppressed is returned instead.
     */
    public static function suppressIfLowVotes(mixed $rating, mixed $voteCount, mixed $suppressed = ''): mixed
    {
        return self::isVoteCountBelowThreshold($voteCount) ? $suppressed : $rating;
    }
}
