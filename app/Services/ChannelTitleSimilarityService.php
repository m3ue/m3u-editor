<?php

namespace App\Services;

use App\Models\Channel;

/**
 * Compares two channel titles to decide whether they plausibly describe the
 * same channel.
 *
 * This exists because merging trusts its grouping key completely. When a
 * provider hands over a bad stream ID - or the importer mis-parses one - every
 * channel sharing that key is treated as the same channel, and unrelated
 * channels end up as each other's failovers. A viewer whose channel drops then
 * gets something entirely different.
 *
 * Comparing the raw titles does not work. Provider titles carry a source
 * prefix and a pile of quality decorations, and those dominate the comparison:
 * "DE| SYFY HEVC" and "DE| SYFY FHD" are the same channel but share little
 * literal text. So titles are reduced to a bare core name first, and only
 * those cores are compared.
 */
class ChannelTitleSimilarityService
{
    /**
     * Quality and format tokens that say nothing about channel identity.
     *
     * Kept deliberately narrow. Anything that can appear inside a real channel
     * name has to stay out, because stripping it merges channels that are not
     * the same - with 'ts' and 'vip' in here, "TS TV" and "VIP TV" both reduced
     * to "tv". 'live' is out for the same reason: "CANAL+ LIVE 14" is the
     * channel's actual name.
     */
    private const NOISE_TOKENS = [
        'fhd', 'uhd', 'hd', 'sd', 'hq', 'lq', '4k', '8k', '1080p', '720p', '576p', '480p',
        'hevc', 'h265', 'h264', 'x265', 'x264', 'raw', 'm3u8', 'mpegts',
        'backup', 'multi',
    ];

    /**
     * Superscript and decorative characters providers use as quality markers,
     * e.g. "UK: BBC ONE HD" written with superscripts, or a trailing bullet.
     */
    private const DECORATIONS = [
        "\u{1D34}", "\u{1D30}", "\u{1D3F}", "\u{1D2C}", "\u{1D42}", "\u{02B0}", "\u{1D49}",
        "\u{1D5B}", "\u{1D9C}", "\u{1D41}", "\u{1D3E}", "\u{1DA0}", "\u{02E2}",
        "\u{2074}", "\u{1D4F}", "\u{00B3}", "\u{2078}", "\u{2070}", "\u{2075}",
        "\u{00B9}", "\u{00B2}", "\u{25C9}", "\u{2605}", "\u{2606}", "\u{26BD}", "\u{25CF}",
    ];

    /**
     * Shortest core name that carries enough signal to compare, or to accept as
     * a containment match. "E!" reduces to "e", which sits inside "espn".
     */
    private const MIN_COMPARABLE_LENGTH = 5;

    /**
     * Longest input similar_text() is allowed to see. It runs in O(n^3) and a
     * pair of 3,600-character names takes about 2.7 seconds; a provider feed is
     * untrusted input and merging walks tens of thousands of channels.
     *
     * Truncation alone would let two names differing only past the cap score as
     * identical, so anything longer than the cap is scored on its head and its
     * tail, keeping whichever agrees less.
     */
    private const MAX_SCORED_LENGTH = 128;

    /**
     * Shortest trailing segment that makes a title look like an event feed
     * rather than a sibling channel. "DAZN 7 - OH Leuven - Standard" qualifies;
     * "SKY SPORTS - NEWS" does not, and Sky Sports News is its own channel.
     */
    private const MIN_EVENT_DETAIL_LENGTH = 12;

    /**
     * Decide whether a failover candidate plausibly describes the same channel
     * as its master.
     *
     * Abstains (returns true) whenever it cannot judge safely, so enabling the
     * guard can only ever remove pairs it is confident about.
     *
     * @param  float  $threshold  0.0 disables the check entirely.
     */
    public function isPlausibleMatch(Channel $master, Channel $candidate, float $threshold): bool
    {
        if ($threshold <= 0.0) {
            return true;
        }

        return $this->titlesMatch(
            $this->effectiveTitle($master),
            $this->effectiveTitle($candidate),
            $threshold,
        );
    }

    /**
     * Compare two raw provider titles.
     */
    public function titlesMatch(?string $masterTitle, ?string $candidateTitle, float $threshold): bool
    {
        if ($threshold <= 0.0) {
            return true;
        }

        // Two titles that both carry a release year and disagree about it are
        // different films. Settle that before normalising, which drops the year
        // and would leave "The Thing (1982)" and "The Thing (2011)" identical.
        if ($this->yearsConflict($masterTitle, $candidateTitle)) {
            return false;
        }

        $master = $this->coreName($masterTitle);
        $candidate = $this->coreName($candidateTitle);

        // Nothing usable left after normalising - don't guess.
        if ($master === null || $candidate === null) {
            return true;
        }

        if ($master === $candidate) {
            return true;
        }

        // Event feeds name the fixture in the title: "DAZN 7 - OH Leuven vs
        // Standard" is the same channel as plain "DAZN 7".
        if ($this->eventFeedMatches($masterTitle, $candidateTitle)) {
            return true;
        }

        // An abbreviation sitting inside its expanded form ("NICK JUNIOR" in
        // "NICKELODEON JUNIOR"). The contained name has to be substantial and
        // sit at one end, otherwise "e" matches "espn" and every short name
        // matches half the channel list.
        if ($this->isAnchoredContainment($master, $candidate)) {
            return true;
        }

        // The same idea at word level, which is the only place the difference
        // between "E!" and "E! ENTERTAINMENT" survives. Squashing to a bare
        // core loses the boundary, leaving "e" - which then sits inside "espn"
        // just as happily. Comparing word sequences keeps the first pair and
        // rejects the second.
        if ($this->isTokenPrefixOrSuffix($masterTitle, $candidateTitle)) {
            return true;
        }

        // Two very short names carry too little signal to judge either way.
        // Only abstain when both are short; a short name against a long
        // unrelated one is genuine evidence of a mismatch.
        if (mb_strlen($master) < self::MIN_COMPARABLE_LENGTH
            && mb_strlen($candidate) < self::MIN_COMPARABLE_LENGTH) {
            return true;
        }

        return $this->similarity($master, $candidate) >= $threshold;
    }

    /**
     * Reduce a provider title to a bare channel name: no source prefix, no
     * quality tokens, no punctuation.
     *
     * "VIP: SKY SPORTS F1 HD HEVC" becomes "skysportsf1".
     */
    public function coreName(?string $title): ?string
    {
        $value = mb_strtolower(trim((string) $title));

        if ($value === '') {
            return null;
        }

        // Strip a leading source prefix: "VIP:", "DE|", "SKYGO:", "NEXT |".
        // Internal spaces are not allowed, so "BBC NEWS: WORLD" keeps its name.
        // An earlier version matched any 12 characters and reduced both
        // "BBC NEWS: WORLD" and "CNN NEWS: WORLD" to "world".
        $value = preg_replace('/^[\p{L}\p{N}+._!-]{1,8}\s?[|:]\s*/u', '', $value) ?? $value;
        $value = preg_replace('/^[\p{L}]{2,6}\s+-\s+/u', '', $value) ?? $value;

        // Drop bracketed asides only when they are purely format markers such
        // as "(SAT)" or "(720P)". Anything else is identity - a year, or a
        // region as in "BBC One (Scotland)" against "BBC One (London)".
        $value = preg_replace_callback(
            '/\(([^()]*)\)|\[([^\[\]]*)\]/u',
            function (array $matches): string {
                $inner = $matches[1] !== '' ? $matches[1] : ($matches[2] ?? '');

                return $this->isNoiseOnly($inner) ? ' ' : ' '.$inner.' ';
            },
            $value
        ) ?? $value;

        $value = str_replace(self::DECORATIONS, ' ', $value);

        // Remove quality tokens as whole words only, so "HD" goes but the "hd"
        // inside a real name does not.
        foreach (self::NOISE_TOKENS as $token) {
            $value = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($token, '/').'(?![\p{L}\p{N}])/u', ' ', $value) ?? $value;
        }

        // Keep letters and digits; everything else was formatting.
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';

        if ($value !== '') {
            return $value;
        }

        // Everything was stripped, so the title was nothing but tokens this
        // class treats as noise - "UK| HD", say. Fall back to the raw
        // alphanumerics rather than returning null, which would make the caller
        // abstain on a title it could still have compared.
        $fallback = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim((string) $title))) ?? '';

        return $fallback !== '' ? $fallback : null;
    }

    /**
     * Similarity between two normalised names, 0.0 to 1.0.
     *
     * similar_text() is not commutative - it can return a different figure
     * depending on argument order, and on real data that flipped the verdict
     * for a handful of pairs depending on which channel won master selection.
     * Both directions are scored and the higher figure kept.
     *
     * Names longer than the cap are scored on their head *and* their tail, and
     * the lower of the two scores wins. Scoring only the head would let two
     * names that differ solely past character 128 come back as identical.
     *
     * A length-ratio penalty was tried here and removed: on 12,754 real pairs
     * it bought 17 extra catches for a tenfold worse false-positive rate,
     * because legitimate pairs like "NAT GEO WILD" against
     * "NAT GEOGRAPHIC WILD" differ in length by design.
     */
    public function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $score = $this->scoreSegment(
            mb_substr($a, 0, self::MAX_SCORED_LENGTH),
            mb_substr($b, 0, self::MAX_SCORED_LENGTH),
        );

        if (mb_strlen($a) > self::MAX_SCORED_LENGTH || mb_strlen($b) > self::MAX_SCORED_LENGTH) {
            $score = min($score, $this->scoreSegment(
                mb_substr($a, -self::MAX_SCORED_LENGTH),
                mb_substr($b, -self::MAX_SCORED_LENGTH),
            ));
        }

        return round($score, 4);
    }

    /**
     * Score one bounded pair of segments in both directions, since
     * similar_text() is not commutative.
     */
    private function scoreSegment(string $a, string $b): float
    {
        similar_text($a, $b, $forward);
        similar_text($b, $a, $reverse);

        return max($forward, $reverse) / 100;
    }

    /**
     * The normalised words of a title, boundaries intact.
     *
     * @return array<int, string>
     */
    public function coreTokens(?string $title): array
    {
        $core = $this->coreName($title);

        if ($core === null) {
            return [];
        }

        // Re-run the normalisation, but split on the separators instead of
        // deleting them, so the word boundaries survive.
        $value = mb_strtolower(trim((string) $title));
        $value = preg_replace('/^[\p{L}\p{N}+._!-]{1,8}\s?[|:]\s*/u', ' ', $value) ?? $value;
        $value = preg_replace('/^[\p{L}]{2,6}\s+-\s+/u', ' ', $value) ?? $value;
        $value = str_replace(self::DECORATIONS, ' ', $value);

        $words = preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $words,
            fn (string $word) => ! in_array($word, self::NOISE_TOKENS, true)
                && preg_match('/^\d{3,4}[ip]$/', $word) !== 1
        ));
    }

    /**
     * True when one title's words are a leading or trailing run of the other's.
     *
     * ["e"] against ["e", "entertainment"] matches; ["e"] against ["espn"] does
     * not, because a whole word has to line up rather than a few characters.
     */
    private function isTokenPrefixOrSuffix(?string $masterTitle, ?string $candidateTitle): bool
    {
        $a = $this->coreTokens($masterTitle);
        $b = $this->coreTokens($candidateTitle);

        if ($a === [] || $b === []) {
            return false;
        }

        [$short, $long] = count($a) <= count($b) ? [$a, $b] : [$b, $a];

        if (count($short) === count($long)) {
            return false;
        }

        return array_slice($long, 0, count($short)) === $short
            || array_slice($long, -count($short)) === $short;
    }

    /**
     * True when one core name sits at the start or end of the other and is long
     * enough to mean something on its own.
     *
     * Anchoring matters: an unanchored check accepts any short name found
     * anywhere inside a longer one, which is how "e" ends up matching "espn".
     */
    private function isAnchoredContainment(string $a, string $b): bool
    {
        [$short, $long] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];

        if (mb_strlen($short) < self::MIN_COMPARABLE_LENGTH) {
            return false;
        }

        return str_starts_with($long, $short) || str_ends_with($long, $short);
    }

    /**
     * True when both titles carry a four-digit year and the years differ.
     *
     * Years only show up on VOD titles, so a disagreement is decisive: two
     * films with the same name and different years are different films.
     */
    private function yearsConflict(?string $masterTitle, ?string $candidateTitle): bool
    {
        $master = $this->releaseYear($masterTitle);
        $candidate = $this->releaseYear($candidateTitle);

        return $master !== null && $candidate !== null && $master !== $candidate;
    }

    /**
     * Pull a plausible release year out of a title, or null when there isn't one.
     */
    private function releaseYear(?string $title): ?string
    {
        if (preg_match('/\((19|20)(\d{2})\)/', (string) $title, $matches) === 1) {
            return $matches[1].$matches[2];
        }

        return null;
    }

    /**
     * True when one title is an event-specific feed of the other, e.g.
     * "BE| DAZN 7 - OH Leuven - Standard" against "BE - DAZN 7".
     *
     * The comparison is asymmetric on purpose: the stripped form of one title
     * is matched against the whole core name of the other. Comparing both
     * stripped forms would collapse anything sharing a leading language code -
     * "EN - Detective Chinatown" and "EN - Thirteen Lives" would both reduce to
     * "en" and be declared the same channel.
     *
     * The trailing segment also has to actually look like event detail.
     * Without that, any "X - Y" title matches a plain "X", and
     * "SKY SPORTS - NEWS" becomes a failover for "SKY SPORTS" despite being a
     * channel in its own right.
     */
    private function eventFeedMatches(?string $masterTitle, ?string $candidateTitle): bool
    {
        $masterFull = $this->coreName($masterTitle);
        $candidateFull = $this->coreName($candidateTitle);

        $pairs = [
            [$this->coreName($this->beforeSeparator($masterTitle)), $candidateFull, $this->afterSeparator($masterTitle)],
            [$this->coreName($this->beforeSeparator($candidateTitle)), $masterFull, $this->afterSeparator($candidateTitle)],
        ];

        foreach ($pairs as [$base, $full, $detail]) {
            if ($base === null || $full === null || $detail === null) {
                continue;
            }

            if (mb_strlen($base) < self::MIN_COMPARABLE_LENGTH) {
                continue;
            }

            if (! $this->looksLikeEventDetail($detail)) {
                continue;
            }

            if ($base === $full) {
                return true;
            }
        }

        return false;
    }

    /**
     * A fixture reads like "OH Leuven - Standard Liege" - several words, and
     * long. A sibling channel's suffix reads like "NEWS".
     */
    private function looksLikeEventDetail(string $detail): bool
    {
        $detail = trim($detail);

        return mb_strlen($detail) >= self::MIN_EVENT_DETAIL_LENGTH && str_contains($detail, ' ');
    }

    /**
     * True when bracketed text is nothing but format markers, e.g. "(SAT)" or
     * "(720P)", as opposed to "(1982)" or "(Scotland)", which are identity.
     */
    private function isNoiseOnly(string $inner): bool
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($inner)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return true;
        }

        foreach ($words as $word) {
            if (! in_array($word, self::NOISE_TOKENS, true) && preg_match('/^\d{3,4}[ip]$/', $word) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Everything before the first " - ", which is where providers append
     * fixture or event detail.
     */
    private function beforeSeparator(?string $title): ?string
    {
        $value = (string) $title;
        $position = mb_strpos($value, ' - ');

        return $position === false ? $value : mb_substr($value, 0, $position);
    }

    /**
     * Everything after the first " - ", or null when there is no separator.
     */
    private function afterSeparator(?string $title): ?string
    {
        $value = (string) $title;
        $position = mb_strpos($value, ' - ');

        return $position === false ? null : mb_substr($value, $position + 3);
    }

    /**
     * The title actually shown for a channel, honouring the user's override and
     * falling back to the name fields, which is what merging itself falls back
     * to when a channel carries no title.
     */
    private function effectiveTitle(Channel $channel): ?string
    {
        return $channel->title_custom
            ?: $channel->title
            ?: $channel->name_custom
            ?: $channel->name;
    }
}
