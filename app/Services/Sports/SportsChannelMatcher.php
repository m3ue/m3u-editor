<?php

namespace App\Services\Sports;

use App\Http\Controllers\PlaylistGenerateController;
use App\Models\Epg;
use App\Services\EpgCacheService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Finds which of a playlist's enabled live channels show each game of a day,
 * on demand, from channel titles and the guide. The guide is read per EPG and
 * by air time, so its cost follows the EPG's size rather than the channel count.
 */
class SportsChannelMatcher
{
    private const int MAX_CHANNELS_PER_GAME = 30;

    /** A programme counts as the game when on air in this span after kickoff, which rules out pre-game shows. */
    private const int AIRS_FROM_SECONDS = 600;

    private const int AIRS_UNTIL_SECONDS = 2700;

    /** Several TVs opening the same day share one run. */
    private const int CACHE_SECONDS = 300;

    /** Past this, a request answers with what it has found rather than holding a PHP worker. */
    private const int TIME_BUDGET_SECONDS = 20;

    private const int MATCH_PROGRAMME_TITLE = 0;

    private const int MATCH_CHANNEL_TITLE = 1;

    private const int MATCH_PROGRAMME_SUBTITLE = 2;

    public function __construct(
        private readonly SportsAedResolver $resolver,
        private readonly EpgCacheService $epgCache,
    ) {}

    /**
     * @param  list<string>  $leagueIds
     * @return array<string, list<array{stream_id: int, program_title: string|null}>> by `{league_id}:{fixture_id}`
     */
    public function channelsFor(Model $playlist, array $leagueIds, CarbonInterface $from, CarbonInterface $to): array
    {
        sort($leagueIds);
        $key = 'sports:channels:'.sha1(implode('|', [$playlist::class, $playlist->getKey(), implode(',', $leagueIds), $from->getTimestamp(), $to->getTimestamp()]));

        return Cache::remember($key, self::CACHE_SECONDS, fn (): array => $this->match($playlist, $leagueIds, $from, $to));
    }

    /**
     * @param  list<string>  $leagueIds
     * @return array<string, list<array{stream_id: int, program_title: string|null}>>
     */
    private function match(Model $playlist, array $leagueIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $games = $this->resolver->gamesBetween($leagueIds, $from, $to);
        if ($games['fixtures'] === []) {
            return [];
        }

        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        $found = [];
        $textGames = [];
        $gameFor = function (string $text) use (&$textGames, $games, $from): ?array {
            // Guides and channels repeat the same text many times over
            if (! array_key_exists($text, $textGames)) {
                $textGames[$text] = $this->resolver->gameNamedBy($games, $text, $from);
            }

            return $textGames[$text];
        };

        $channelsByGuide = [];
        $channels = PlaylistGenerateController::getChannelQuery($playlist, isVod: false)
            ->reorder()
            ->select(['channels.id', 'channels.title', 'channels.title_custom', 'epg_channels.epg_id as epg_id', 'epg_channels.channel_id as epg_channel_key'])
            ->toBase()
            ->cursor();
        foreach ($channels as $channel) {
            $game = $gameFor((string) ($channel->title_custom ?? $channel->title));
            if ($game !== null) {
                $this->record($found, $game, (int) $channel->id, self::MATCH_CHANNEL_TITLE, null);
            }
            if ($channel->epg_id !== null && $channel->epg_channel_key !== null) {
                $channelsByGuide[$channel->epg_id][$channel->epg_channel_key][] = (int) $channel->id;
            }
        }

        $kickoffs = array_values(array_unique(array_column($games['fixtures'], 'start_ts')));
        sort($kickoffs);
        $finished = true;
        foreach (Epg::query()->whereIn('id', array_keys($channelsByGuide))->get() as $epg) {
            $guideChannels = $channelsByGuide[$epg->id];
            $airing = $this->epgCache->programmesAiring(
                $epg,
                $kickoffs[0] + self::AIRS_FROM_SECONDS,
                $kickoffs[array_key_last($kickoffs)] + self::AIRS_UNTIL_SECONDS,
            );
            foreach ($airing as $programme) {
                if (! isset($guideChannels[$programme['channel_id']]) || ! $this->airsAfterAnyKickoff($programme, $kickoffs)) {
                    continue;
                }
                if (microtime(true) > $deadline) {
                    $finished = false;

                    break 2;
                }

                $strength = self::MATCH_PROGRAMME_TITLE;
                $game = $gameFor($programme['title']);
                if ($game === null && $programme['subtitle'] !== '') {
                    $strength = self::MATCH_PROGRAMME_SUBTITLE;
                    $game = $gameFor(trim($programme['title'].' '.$programme['subtitle']));
                }
                if ($game === null || ! $this->airsAfterKickoff($programme, $game['start_ts'])) {
                    continue;
                }
                foreach ($guideChannels[$programme['channel_id']] as $channelId) {
                    $this->record($found, $game, $channelId, $strength, $programme['title']);
                }
            }
        }
        if (! $finished) {
            Log::warning('Sports channel matching stopped at its time budget', [
                'playlist' => $playlist::class.':'.$playlist->getKey(),
                'leagues' => count($leagueIds),
            ]);
        }

        return array_map(function (array $channels): array {
            usort($channels, fn (array $a, array $b): int => [$a['strength'], $a['stream_id']] <=> [$b['strength'], $b['stream_id']]);

            return array_map(
                fn (array $channel): array => ['stream_id' => $channel['stream_id'], 'program_title' => $channel['program_title']],
                array_slice($channels, 0, self::MAX_CHANNELS_PER_GAME),
            );
        }, $found);
    }

    /**
     * Keeps a channel's strongest match for a game.
     *
     * @param  array<string, array<int, array{stream_id: int, strength: int, program_title: string|null}>>  $found
     * @param  array<string, mixed>  $game
     */
    private function record(array &$found, array $game, int $channelId, int $strength, ?string $programTitle): void
    {
        $key = $game['league_id'].':'.$game['id'];
        $existing = $found[$key][$channelId] ?? null;
        if ($existing === null || $strength < $existing['strength']) {
            $found[$key][$channelId] = [
                'stream_id' => $channelId,
                'strength' => $strength,
                'program_title' => $programTitle ?? $existing['program_title'] ?? null,
            ];
        }
    }

    /**
     * @param  array{start_ts: int, stop_ts: int|null}  $programme
     * @param  list<int>  $kickoffs  Ascending
     */
    private function airsAfterAnyKickoff(array $programme, array $kickoffs): bool
    {
        foreach ($kickoffs as $kickoff) {
            if ($this->airsAfterKickoff($programme, $kickoff)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{start_ts: int, stop_ts: int|null}  $programme
     */
    private function airsAfterKickoff(array $programme, int $kickoff): bool
    {
        return $programme['start_ts'] < $kickoff + self::AIRS_UNTIL_SECONDS
            && ($programme['stop_ts'] === null || $programme['stop_ts'] > $kickoff + self::AIRS_FROM_SECONDS);
    }
}
