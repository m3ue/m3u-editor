<?php

namespace App\Services\Sports;

use App\Enums\ImageProfile;
use App\Http\Controllers\LogoProxyController;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * What every sports provider shares when it maps its answers to fixtures,
 * and when those are sent on to clients.
 */
trait MapsSportsData
{
    /** Sports whose fixtures read home side first ("Arsenal v Chelsea"). */
    private const array HOME_FIRST_SPORTS = ['soccer', 'rugby', 'rugby-league', 'australian-football', 'cricket', 'handball', 'netball', 'gaelic'];

    /** Display names of the sports the catalogues hold, in their order. */
    private const array SPORT_NAMES = [
        'football' => 'Football',
        'basketball' => 'Basketball',
        'baseball' => 'Baseball',
        'hockey' => 'Hockey',
        'soccer' => 'Soccer',
        'rugby' => 'Rugby',
        'rugby-league' => 'Rugby League',
        'australian-football' => 'Australian Football',
        'lacrosse' => 'Lacrosse',
        'volleyball' => 'Volleyball',
        'field-hockey' => 'Field Hockey',
        'water-polo' => 'Water Polo',
        'cricket' => 'Cricket',
        'handball' => 'Handball',
        'netball' => 'Netball',
        'gaelic' => 'Gaelic Games',
    ];

    /**
     * A sport's display name ("Rugby League" for "rugby-league").
     */
    public static function sportName(string $sport): string
    {
        return __(self::SPORT_NAMES[$sport] ?? ucwords(str_replace('-', ' ', $sport)));
    }

    /**
     * Whether a league's fixtures read home side first, as soccer's do.
     *
     * @param  array<string, mixed>  $league
     */
    public static function isHomeFirst(array $league): bool
    {
        return in_array($league['sport'], self::HOME_FIRST_SPORTS, true);
    }

    /**
     * The sport a league id belongs to ("hockey" for "hockey/nhl").
     */
    public static function sportOf(string $leagueId): string
    {
        return explode('/', $leagueId, 2)[0];
    }

    /**
     * A crest as clients load it: through the logo proxy, sized as a small
     * image, so nothing outside this server ever reaches a provider.
     */
    public static function proxiedLogo(string $url): string
    {
        return LogoProxyController::generateProxyUrl($url, profile: ImageProfile::Photo);
    }

    /**
     * Swaps each crest for its logo proxy URL, sized as a small image.
     *
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function present(array $fixture): array
    {
        foreach (['home', 'away'] as $side) {
            $logo = $fixture[$side]['team']['logo'] ?? null;
            if ($logo !== null) {
                $fixture[$side]['team']['logo'] = self::proxiedLogo($logo);
            }
        }
        if (($fixture['league_logo'] ?? null) !== null) {
            $fixture['league_logo'] = self::proxiedLogo($fixture['league_logo']);
        }

        return $fixture;
    }

    /**
     * [present] for a game's details, whose leaders may have photos too.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function presentDetails(array $details): array
    {
        $details['fixture'] = $this->present($details['fixture']);
        foreach ($details['leaders'] as &$category) {
            foreach (['home', 'away'] as $side) {
                $photo = $category[$side]['photo'] ?? null;
                if ($photo !== null) {
                    $category[$side]['photo'] = self::proxiedLogo($photo);
                }
            }
        }
        unset($category);

        return $details;
    }

    /**
     * How long a game's details stay good: [$liveSeconds] while it's on or
     * about to start, longer the less can still change.
     *
     * @param  array<string, mixed>  $fixture
     */
    private static function detailsExpiry(array $fixture, int $liveSeconds): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');
        $start = CarbonImmutable::parse($fixture['start']);

        return match ($fixture['state']) {
            'live' => $now->addSeconds($liveSeconds),
            'scheduled' => $start->lessThan($now->addMinutes(30)) ? $now->addSeconds($liveSeconds) : $now->addMinutes(15),
            'finished' => $start->addDays(2)->lessThan($now) ? $now->addHours(6) : $now->addMinutes(10),
            default => $now->addHour(),
        };
    }

    /**
     * ESPN instants are UTC and sometimes drop the seconds (`2026-11-04T00:00Z`);
     * TheSportsDB's are UTC with no offset at all, which is why they're read as
     * UTC rather than in the server's time zone.
     */
    private static function parseDate(?string $raw): ?CarbonImmutable
    {
        if ($raw === null) {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private static function hex(mixed $raw): ?string
    {
        $hex = ltrim((string) self::string($raw), '#');

        return preg_match('/^[0-9a-fA-F]{6}$/', $hex) === 1 ? strtolower($hex) : null;
    }

    private static function string(mixed $raw): ?string
    {
        if (! is_scalar($raw)) {
            return null;
        }
        $text = trim((string) $raw);

        return $text === '' ? null : $text;
    }
}
