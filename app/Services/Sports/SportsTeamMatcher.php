<?php

namespace App\Services\Sports;

use Illuminate\Support\Str;

/**
 * Pure text matching between a fixture's teams and a channel title (the same
 * rules as M3U TV's `SportsMatcher`, so both apps agree on what names a team).
 *
 * A team is "named" by a text when one of its phrases (full name, short name,
 * nickname, a known alias) appears in it as whole words, after both are
 * lowercased, accent-folded and stripped to letters and digits. A lone place
 * name ("Toronto", "New York") is never a phrase: one city fields teams in
 * several leagues. Whole words matter: "Nets" must not match "Hornets".
 */
class SportsTeamMatcher
{
    /**
     * Phrases that can't stand for one team on their own.
     */
    private const array TOO_GENERIC = [
        'united', 'city', 'club', 'real', 'sporting', 'athletic', 'athletics', 'state',
        'college', 'university', 'los angeles', 'new york', 'new england',
        'san francisco', 'chicago', 'toronto', 'washington',
    ];

    /**
     * Short forms guides and channel names use that the provider's names don't
     * cover, keyed by the provider's normalized full name.
     *
     * @var array<string, list<string>>
     */
    private const array ALIASES = [
        'manchester united' => ['Man Utd', 'Man United'],
        'manchester city' => ['Man City'],
        'tottenham hotspur' => ['Tottenham', 'Spurs'],
        'wolverhampton wanderers' => ['Wolves', 'Wolverhampton'],
        'brighton hove albion' => ['Brighton'],
        'newcastle united' => ['Newcastle'],
        'west ham united' => ['West Ham'],
        'nottingham forest' => ["Nott'm Forest", 'Forest'],
        'leeds united' => ['Leeds'],
        'paris saint germain' => ['PSG', 'Paris SG', 'Paris'],
        'bayern munich' => ['Bayern', 'Bayern Munchen', 'FC Bayern'],
        'borussia dortmund' => ['Dortmund', 'BVB'],
        'borussia monchengladbach' => ['Gladbach', 'Monchengladbach'],
        'bayer leverkusen' => ['Leverkusen'],
        'internazionale' => ['Inter', 'Inter Milan'],
        'ac milan' => ['Milan'],
        'juventus' => ['Juve'],
        'atletico madrid' => ['Atletico', 'Atleti'],
        'barcelona' => ['Barca', 'FC Barcelona'],
        'athletic club' => ['Athletic Bilbao', 'Bilbao'],
        'inter miami cf' => ['Inter Miami'],
        'la galaxy' => ['Galaxy'],
        'new york red bulls' => ['Red Bulls', 'NY Red Bulls'],
        'new york city fc' => ['NYCFC', 'NYC FC'],
        'los angeles fc' => ['LAFC'],
        'los angeles lakers' => ['LA Lakers'],
        'los angeles clippers' => ['LA Clippers'],
        'la clippers' => ['Los Angeles Clippers'],
        'los angeles kings' => ['LA Kings'],
        'los angeles rams' => ['LA Rams'],
        'los angeles chargers' => ['LA Chargers'],
        'los angeles dodgers' => ['LA Dodgers'],
        'los angeles angels' => ['LA Angels'],
        'new york rangers' => ['NY Rangers'],
        'new york islanders' => ['NY Islanders'],
        'new york knicks' => ['NY Knicks'],
        'new york giants' => ['NY Giants'],
        'new york jets' => ['NY Jets'],
        'new york yankees' => ['NY Yankees'],
        'new york mets' => ['NY Mets'],
    ];

    /**
     * Lowercased, accent-folded, every run of non-alphanumerics collapsed to one
     * space, padded with a space each end so a phrase is found as " {phrase} ".
     */
    public static function normalize(string $text): string
    {
        $folded = Str::lower(Str::ascii($text));
        $words = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return ' '.implode(' ', $words).' ';
    }

    /**
     * Whether [$normalizedText] (from [normalize]) names any of [$phrases].
     *
     * @param  list<string>  $phrases
     */
    public static function mentions(string $normalizedText, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains($normalizedText, ' '.$phrase.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The normalized phrases that name a fixture team (the `team` array of an
     * EspnSportsService fixture).
     *
     * A [$college] team is named by its school ("Southern Illinois", the place
     * ESPN keeps in `location`, since it abbreviates the short name to "S
     * Illinois"), never by its nickname alone: hundreds of schools share
     * "Wolves" or "Cougars". A pro team's nickname is distinctive in its
     * league, while its place is a city fielding teams in several, so pros
     * are the other way round.
     *
     * @param  array<string, mixed>  $team
     * @return list<string>
     */
    public static function phrasesFor(array $team, bool $college = false): array
    {
        $names = [
            (string) ($team['display_name'] ?? ''),
            (string) ($team['short_name'] ?? ''),
        ];
        if ($college) {
            $names[] = (string) ($team['location'] ?? '');
        } else {
            $names[] = (string) ($team['nickname'] ?? '');
        }
        if (preg_match('/^(?:(?:AFC|FC|CF|SC|CD|AC|SV|VfB|VfL|TSG|RCD|SSC|AS)\s+)?(.+?)(?:\s+(?:FC|CF|SC|AFC))?$/u', $names[0], $matches)) {
            $names[] = $matches[1];
        }
        $names = [...$names, ...(self::ALIASES[trim(self::normalize($names[0]))] ?? [])];

        $phrases = [];
        foreach ($names as $name) {
            $phrase = trim(self::normalize($name));
            if (mb_strlen($phrase) >= 3 && ! in_array($phrase, self::TOO_GENERIC, true)) {
                $phrases[$phrase] = true;
            }
        }

        return array_keys($phrases);
    }
}
