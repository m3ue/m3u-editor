<?php

namespace App\Console\Commands;

use App\Enums\PlaylistChannelId;
use App\Jobs\MapPlaylistChannelsToEpg;
use App\Jobs\MapPlaylistChannelsToEpgChunk;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\Job;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PDO;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;

/**
 * Times the real EPG matcher (MapPlaylistChannelsToEpgChunk) against
 * deterministic synthetic data, or an existing EPG map's real channels
 * (--map), so a matching change can be compared with its base branch before
 * it ships. Everything it writes lives inside a transaction that is always
 * rolled back, so it never leaves rows behind.
 */
class BenchmarkEpgMapping extends Command
{
    use ConfirmableTrait;

    protected $signature = 'epg:benchmark-mapping
        {--map= : Use an existing EPG map\'s channels, EPG and settings instead of synthetic data}
        {--channels=500 : Playlist channels to map (with --map, how many of the map\'s channels to use, in id order)}
        {--epg-channels=10000 : EPG channels to match against}
        {--iterations=3 : Timed runs, after one untimed warm-up run}
        {--seed=1581 : Seed for the synthetic data - keep it the same when comparing two runs}
        {--trigram : Turn on the map\'s pg_trgm matching setting (Postgres with pg_trgm only)}
        {--settings= : Extra EPG map settings as JSON, e.g. {"remove_quality_indicators":true}}
        {--explain : Print EXPLAIN (ANALYZE, BUFFERS) for the slowest queries (Postgres only)}
        {--json= : Write the results to this file}
        {--compare= : Compare against a results file written by an earlier run}
        {--force : Run in production without asking}';

    protected $description = 'Benchmark EPG channel mapping on synthetic data or an existing EPG map (inside a transaction that is always rolled back)';

    /** Genre words shared by many guide channels, like "sports" or "news" in real guides. */
    private const TOPICS = [
        'action', 'anime', 'auto', 'baseball', 'boxing', 'business', 'cinema', 'classic',
        'comedy', 'cooking', 'cricket', 'crime', 'documentary', 'drama', 'extra', 'family',
        'fashion', 'fitness', 'football', 'gaming', 'garden', 'gold', 'golf', 'health',
        'history', 'hockey', 'kids', 'lifestyle', 'music', 'mystery', 'nature', 'news',
        'outdoor', 'premium', 'racing', 'reality', 'rugby', 'science', 'select', 'shopping',
        'soccer', 'sports', 'tennis', 'travel', 'weather', 'western', 'world', 'wrestling',
    ];

    private const SYLLABLES = [
        'ar', 'bel', 'bo', 'con', 'cri', 'del', 'den', 'dor', 'ex', 'fi', 'ger', 'ha', 'jun', 'ka',
        'ko', 'lim', 'lo', 'lu', 'mar', 'mi', 'no', 'nor', 'pa', 'pri', 'qui', 'ra', 'ros', 'sa',
        'sen', 'sol', 'tel', 'to', 'tri', 'tur', 'val', 've', 'vis', 'vo', 'xa', 'zen',
    ];

    /**
     * Brands for channels the guide doesn't carry. A separate syllable set
     * keeps them from landing one syllable away from a real guide brand,
     * so a match on one is the matcher overreaching, not a near-duplicate.
     */
    private const UNMATCHED_SYLLABLES = [
        'ash', 'bry', 'cus', 'dwi', 'eft', 'gry', 'hux', 'ipp', 'jyn', 'kew', 'lyf', 'mox',
        'nym', 'oth', 'pyr', 'quo', 'rhy', 'sty', 'thy', 'umb', 'vex', 'wyl', 'yar', 'zog',
    ];

    private const COUNTRIES = ['us', 'uk', 'ca', 'de', 'fr', 'es', 'it', 'nl', 'au'];

    private const NETWORKS = ['ABC', 'CBS', 'NBC', 'FOX', 'CW', 'PBS'];

    private const CITIES = ['Austin', 'Boise', 'Denver', 'Fresno', 'Orlando', 'Reno', 'Stockton', 'Tampa'];

    /** Provider-style decoration wrapped around playlist channel names. */
    private const PREFIXES = ['US: ', 'UK| ', 'CA - ', '[DE] ', 'FR: ', 'VIP ', '|EN| ', ''];

    private const SUFFIXES = [' HD', ' FHD', ' 4K', ' SD', ' ᴴᴰ', ' 1080p', ' (Backup)', ''];

    /**
     * How playlist channels relate to the guide (percentages), so each
     * matcher step gets exercised roughly as often as on a real playlist.
     */
    private const PATTERNS = [
        'tvg_id' => 15, // carries the guide's channel id (exact channel_id lookup)
        'exact_name' => 10, // same name as the guide (exact name lookup)
        'callsign' => 5, // US local with "(KXYZ)" in the name (callsign lookup)
        'noisy' => 50, // prefixes, suffixes, case changes and typos (similarity search)
        'unmatched' => 20, // not in the guide at all, so any match is a false positive
    ];

    private const EXPLAINED_QUERIES = 3;

    private const REPORTED_QUERY_SHAPES = 5;

    private Randomizer $random;

    /** @var array<string, true> */
    private array $usedBrands = [];

    private bool $recording = false;

    private bool $captureSlowest = false;

    private int $queryCount = 0;

    private float $queryMilliseconds = 0;

    /** @var array<string, array{sql: string, bindings: array<int, mixed>, time: float}> */
    private array $slowestQueries = [];

    /** @var array<string, array{count: int, milliseconds: float}> */
    private array $queryShapes = [];

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $channelCount = (int) $this->option('channels');
        $epgChannelCount = (int) $this->option('epg-channels');
        $iterations = (int) $this->option('iterations');
        $seed = (int) $this->option('seed');

        if ($channelCount < 1 || $epgChannelCount < 1 || $iterations < 1) {
            $this->components->error('--channels, --epg-channels and --iterations must all be at least 1.');

            return self::FAILURE;
        }

        $settings = json_decode($this->option('settings') ?? '{}', true);
        if (! is_array($settings)) {
            $this->components->error('--settings must be a JSON object, e.g. {"remove_quality_indicators":true}.');

            return self::FAILURE;
        }

        if ($this->option('trigram')) {
            $settings['trigram_matching_enabled'] = true;
        }

        $map = null;
        if ($mapId = $this->option('map')) {
            $map = EpgMap::query()->find($mapId);
            if (! $map) {
                $this->components->error("EPG map {$mapId} not found.");

                return self::FAILURE;
            }

            $settings = [...($map->settings ?? []), ...$settings];
        }

        $baseline = null;
        if ($comparePath = $this->option('compare')) {
            $baseline = is_readable($comparePath) ? json_decode((string) file_get_contents($comparePath), true) : null;
            if (! is_array($baseline) || ! isset($baseline['summary'], $baseline['mappings'])) {
                $this->components->error("Could not read benchmark results from {$comparePath}.");

                return self::FAILURE;
            }
        }

        $driver = DB::connection()->getDriverName();
        $explain = (bool) $this->option('explain');
        if ($explain && $driver !== 'pgsql') {
            $this->components->warn('--explain is only supported on Postgres, skipping it.');
            $explain = false;
        }

        $environment = $this->environment();
        $revision = $this->revision();

        $this->components->info('EPG mapping benchmark');
        $this->components->twoColumnDetail('Revision', $revision ?? 'unknown');
        $this->components->twoColumnDetail('Database', $this->describeEnvironment($environment));
        $this->components->twoColumnDetail('Data', $map
            ? "EPG map {$map->id} \"{$map->name}\""
            : number_format($channelCount).' channels vs '.number_format($epgChannelCount)." EPG channels, seed {$seed}");
        $this->components->twoColumnDetail('Settings', $settings === [] ? 'defaults' : json_encode($settings));
        $this->newLine();

        DB::listen($this->recordQuery(...));

        $runs = [];
        $mappings = null;
        $deterministic = true;
        $explained = [];
        $queryShapes = [];

        DB::beginTransaction();

        try {
            if ($map) {
                $seeded = $this->timedStep('Loading the map\'s channels', fn (): array => $this->loadMap($map, $channelCount, $settings));
                $this->components->twoColumnDetail(
                    'Channels',
                    number_format(count($seeded['channel_ids'])).' of '.number_format($seeded['map_channels']).' vs '.number_format(count($seeded['epg_channel_ids'])).' EPG channels',
                );
            } else {
                $seeded = $this->timedStep('Seeding synthetic data', function () use ($channelCount, $epgChannelCount, $seed, $settings, $driver): array {
                    $seeded = $this->seed($this->generateData($channelCount, $epgChannelCount, $seed), $settings);

                    // Refresh planner statistics the way autovacuum would after a
                    // real import. Inside this transaction ANALYZE still samples
                    // the seeded (uncommitted) rows, and rolls back with them.
                    if ($driver === 'pgsql') {
                        DB::statement('ANALYZE epg_channels');
                        DB::statement('ANALYZE channels');
                    }

                    return $seeded;
                });
            }

            for ($run = 0; $run <= $iterations; $run++) {
                $label = $run === 0 ? 'Warm-up run (not timed)' : "Run {$run} of {$iterations}";

                $this->captureSlowest = $explain && $run === 0;
                $result = $this->timedStep($label, fn (): array => $this->runMatcher($seeded, $settings));
                $this->captureSlowest = false;

                $mappings ??= $result['mappings'];
                $deterministic = $deterministic && $result['mappings'] === $mappings;

                if ($run > 0) {
                    $runs[] = $result['stats'];
                    $queryShapes = $result['query_shapes'];
                }
            }

            if ($explain) {
                $explained = $this->explainSlowestQueries();
            }
        } finally {
            DB::rollBack();
        }

        $results = [
            'version' => 1,
            'revision' => $revision,
            'ran_at' => now()->toIso8601String(),
            'environment' => $environment,
            'options' => [
                'map' => $map?->id,
                'channels' => $channelCount,
                'epg_channels' => $epgChannelCount,
                'iterations' => $iterations,
                'seed' => $seed,
                'settings' => $settings,
            ],
            'runs' => $runs,
            'summary' => $this->summarize($runs),
            'mapped' => count(array_filter($mappings, fn (?string $channelId): bool => $channelId !== null)),
            'quality' => isset($seeded['expected']) ? $this->quality($seeded['expected'], $mappings) : null,
            'deterministic' => $deterministic,
            'mappings_hash' => sha1((string) json_encode($mappings)),
            'mappings' => $mappings,
            'query_shapes' => $queryShapes,
            'explain' => $explained,
        ];

        $this->report($results);

        if ($baseline !== null) {
            $this->compare($baseline, $results);
        }

        if ($jsonPath = $this->option('json')) {
            file_put_contents($jsonPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->components->info("Results written to {$jsonPath}");
        }

        return self::SUCCESS;
    }

    /**
     * Show a step with its elapsed time, like components->task(), but hand
     * back what the step returns.
     *
     * @template T
     *
     * @param  Closure(): T  $step
     * @return T
     */
    private function timedStep(string $label, Closure $step): mixed
    {
        $result = null;

        $this->components->task($label, function () use ($step, &$result): void {
            $result = $step();
        });

        return $result;
    }

    /**
     * Run the matcher over every seeded channel, chunked exactly like
     * MapPlaylistChannelsToEpg dispatches it, and collect its mappings.
     *
     * @param  array{map_id: int, epg_id: int, channel_ids: list<int>, source_ids: list<?string>, epg_channel_ids: array<int, string>}  $seeded
     * @param  array<string, mixed>  $settings
     * @return array{stats: array{seconds: float, queries: int, query_seconds: float, peak_memory_bytes: int}, mappings: array<string, ?string>, query_shapes: list<array{sql: string, count: int, seconds: float}>}
     */
    private function runMatcher(array $seeded, array $settings): array
    {
        $batchNo = Str::orderedUuid()->toString();

        // Start every run from a fresh map, otherwise progress from the
        // previous run hits the 99% cap and later runs skip its updates.
        DB::table('epg_maps')->where('id', $seeded['map_id'])->update(['progress' => 0]);

        $this->queryCount = 0;
        $this->queryMilliseconds = 0;
        $this->queryShapes = [];
        memory_reset_peak_usage();
        $memoryBefore = memory_get_usage();

        // The chunk jobs hand their matches to the next mapping stage as Job
        // rows on the separate jobs connection, which this command's
        // transaction doesn't cover, so they're always cleaned up here.
        $jobs = Job::query()->where('batch_no', $batchNo);

        try {
            $this->recording = true;
            $start = hrtime(true);

            foreach (array_chunk($seeded['channel_ids'], MapPlaylistChannelsToEpg::CHUNK_SIZE) as $chunk) {
                (new MapPlaylistChannelsToEpgChunk(
                    channelIds: $chunk,
                    epgId: $seeded['epg_id'],
                    epgMapId: $seeded['map_id'],
                    settings: $settings,
                    batchNo: $batchNo,
                    totalChannels: count($seeded['channel_ids']),
                ))->handle();
            }

            $seconds = (hrtime(true) - $start) / 1e9;
            $this->recording = false;
            $peakMemory = max(0, memory_get_peak_usage() - $memoryBefore);

            $mappings = array_fill_keys($seeded['source_ids'], null);
            foreach ($jobs->clone()->cursor() as $job) {
                foreach ($job->payload as $row) {
                    $mappings[$row['source_id']] = $seeded['epg_channel_ids'][$row['epg_channel_id']] ?? null;
                }
            }
            ksort($mappings);
        } finally {
            $this->recording = false;
            $jobs->delete();
        }

        return [
            'stats' => [
                'seconds' => round($seconds, 4),
                'queries' => $this->queryCount,
                'query_seconds' => round($this->queryMilliseconds / 1000, 4),
                'peak_memory_bytes' => $peakMemory,
            ],
            'mappings' => $mappings,
            'query_shapes' => collect($this->queryShapes)
                ->sortByDesc('milliseconds')
                ->take(self::REPORTED_QUERY_SHAPES)
                ->map(fn (array $totals, string $shape): array => [
                    'sql' => $shape,
                    'count' => $totals['count'],
                    'seconds' => round($totals['milliseconds'] / 1000, 4),
                ])
                ->values()
                ->all(),
        ];
    }

    private function recordQuery(QueryExecuted $query): void
    {
        if (! $this->recording) {
            return;
        }

        $this->queryCount++;
        $this->queryMilliseconds += $query->time;

        // Statements sharing their first 160 characters are the same query
        // with a different number of search terms (e.g. one candidate
        // prefetch per chunk), so they're totalled, and explained, together.
        $shape = substr($query->sql, 0, 160);
        $this->queryShapes[$shape]['count'] = ($this->queryShapes[$shape]['count'] ?? 0) + 1;
        $this->queryShapes[$shape]['milliseconds'] = ($this->queryShapes[$shape]['milliseconds'] ?? 0) + $query->time;

        if (! $this->captureSlowest
            || $query->connectionName !== DB::getDefaultConnection()
            || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            return;
        }

        if ($query->time > ($this->slowestQueries[$shape]['time'] ?? -1)) {
            $this->slowestQueries[$shape] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'time' => $query->time];
        }
    }

    /**
     * @return list<array{sql: string, time_ms: float, plan: list<string>}>
     */
    private function explainSlowestQueries(): array
    {
        return collect($this->slowestQueries)
            ->sortByDesc('time')
            ->take(self::EXPLAINED_QUERIES)
            ->map(fn (array $query): array => [
                'sql' => $query['sql'],
                'time_ms' => round($query['time'], 2),
                'plan' => collect(DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$query['sql'], $query['bindings']))
                    ->map(fn (object $row): string => (string) ($row->{'QUERY PLAN'} ?? ''))
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Build the synthetic guide and playlist. Brands are made-up words grouped
     * into families ("Kalomi", "Kalomi Sports", "Kalomi 2"), the way real
     * guides carry "ESPN", "ESPN News" and "ESPN 2", and share common genre
     * words so the candidate search has realistic overlap to sift through.
     *
     * @return array{epg: list<array{channel_id: string, name: string, display_name: string, additional_display_names: ?string}>, channels: list<array{source_id: string, name: string, stream_id: ?string, pattern: string, expected: ?string}>}
     */
    private function generateData(int $channelCount, int $epgChannelCount, int $seed): array
    {
        $this->random = new Randomizer(new Mt19937($seed));
        $this->usedBrands = [];

        $epg = [];
        $stations = [];
        $seen = [];

        $stationCount = (int) round($epgChannelCount * 0.03);
        while (count($stations) < $stationCount) {
            $callsign = $this->pick(['K', 'W']).$this->random->getBytesFromString('ABCDEFGHIJKLMNOPQRSTUVWXYZ', 3);
            if (isset($seen[$callsign])) {
                continue;
            }

            $seen[$callsign] = true;
            $network = $this->pick(self::NETWORKS);
            $stations[] = ['callsign' => $callsign, 'network' => $network];
            $epg[] = [
                'channel_id' => "{$callsign}-DT",
                'name' => "{$callsign}-DT",
                'display_name' => "{$callsign}-DT ({$network})",
                'additional_display_names' => null,
            ];
        }

        while (count($epg) < $epgChannelCount) {
            $brand = $this->nextBrand();
            $country = $this->pick(self::COUNTRIES);
            $family = [
                $brand,
                $brand.' '.ucfirst($this->pick(self::TOPICS)),
                $brand.' '.$this->random->getInt(2, 9),
                $brand.' '.ucfirst($this->pick(self::TOPICS)).' TV',
            ];

            foreach (array_slice($family, 0, $this->random->getInt(1, 4)) as $name) {
                $channelId = Str::studly($name).'.'.$country;
                if (count($epg) >= $epgChannelCount || isset($seen[$channelId])) {
                    continue;
                }

                $seen[$channelId] = true;
                $epg[] = [
                    'channel_id' => $channelId,
                    'name' => $name,
                    'display_name' => $name,
                    'additional_display_names' => $this->random->getInt(1, 5) === 1 ? json_encode(["{$name} HD"]) : null,
                ];
            }
        }

        $brandRows = array_slice($epg, count($stations)) ?: $epg;
        $channels = [];

        for ($index = 1; $index <= $channelCount; $index++) {
            $pattern = $this->pickPattern();
            if ($pattern === 'callsign' && $stations === []) {
                $pattern = 'noisy';
            }

            $row = $this->pick($brandRows);
            $streamId = null;
            $expected = $row['channel_id'];

            switch ($pattern) {
                case 'tvg_id':
                    $name = $this->decorate($row['name']);
                    $streamId = $row['channel_id'];
                    break;
                case 'exact_name':
                    $name = $row['name'];
                    break;
                case 'callsign':
                    $station = $this->pick($stations);
                    $name = "US: {$station['network']} ".$this->random->getInt(2, 69)." ({$station['callsign']}) ".$this->pick(self::CITIES).' HD';
                    $expected = "{$station['callsign']}-DT";
                    break;
                case 'noisy':
                    $name = $this->decorate($this->random->getInt(1, 10) <= 3 ? $this->typo($row['name']) : $row['name']);
                    $streamId = $this->random->getInt(0, 1) === 1 ? (string) $this->random->getInt(10000, 999999) : null;
                    break;
                default:
                    $name = $this->decorate($this->nextBrand(self::UNMATCHED_SYLLABLES).' '.ucfirst($this->pick(self::TOPICS)));
                    $expected = null;
            }

            $channels[] = [
                'source_id' => sprintf('bench-%06d', $index),
                'name' => $name,
                'stream_id' => $streamId,
                'pattern' => $pattern,
                'expected' => $expected,
            ];
        }

        return ['epg' => $epg, 'channels' => $channels];
    }

    /**
     * @param  list<string>  $syllables
     */
    private function nextBrand(array $syllables = self::SYLLABLES): string
    {
        do {
            $brand = '';
            for ($syllable = $this->random->getInt(2, 3); $syllable > 0; $syllable--) {
                $brand .= $this->pick($syllables);
            }
        } while (isset($this->usedBrands[$brand]));

        $this->usedBrands[$brand] = true;

        return ucfirst($brand);
    }

    private function pickPattern(): string
    {
        $roll = $this->random->getInt(1, array_sum(self::PATTERNS));

        foreach (self::PATTERNS as $pattern => $weight) {
            if (($roll -= $weight) <= 0) {
                return $pattern;
            }
        }

        return 'noisy';
    }

    private function decorate(string $name): string
    {
        $name = match ($this->random->getInt(0, 2)) {
            0 => $name,
            1 => mb_strtoupper($name),
            default => mb_strtolower($name),
        };

        return $this->pick(self::PREFIXES).$name.$this->pick(self::SUFFIXES);
    }

    /** Drop one character from inside the name, like "Kalmi Sports" for "Kalomi Sports". */
    private function typo(string $name): string
    {
        $position = $this->random->getInt(1, max(1, strlen($name) - 2));

        return substr($name, 0, $position).substr($name, $position + 1);
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[$this->random->getInt(0, count($items) - 1)];
    }

    /**
     * Insert the synthetic guide and playlist with the query builder, so no
     * model events (imports, syncs) fire for the throwaway records.
     *
     * @param  array{epg: list<array<string, ?string>>, channels: list<array{source_id: string, name: string, stream_id: ?string, pattern: string, expected: ?string}>}  $data
     * @param  array<string, mixed>  $settings
     * @return array{map_id: int, epg_id: int, channel_ids: list<int>, source_ids: list<string>, epg_channel_ids: array<int, string>, expected: array<string, array{pattern: string, channel_id: ?string}>}
     */
    private function seed(array $data, array $settings): array
    {
        $now = now();
        $timestamps = ['created_at' => $now, 'updated_at' => $now];
        $name = 'EPG mapping benchmark';

        $userId = DB::table('users')->insertGetId([
            'name' => $name,
            'email' => 'epg-benchmark-'.Str::lower(Str::random(12)).'@example.invalid',
            'password' => Str::random(60),
            ...$timestamps,
        ]);
        $epgId = DB::table('epgs')->insertGetId(['name' => $name, 'user_id' => $userId, ...$timestamps]);
        $playlistId = DB::table('playlists')->insertGetId([
            'name' => $name,
            'uuid' => Str::uuid()->toString(),
            'user_id' => $userId,
            'id_channel_by' => PlaylistChannelId::TvgId->value,
            ...$timestamps,
        ]);
        $groupId = DB::table('groups')->insertGetId([
            'name' => $name,
            'user_id' => $userId,
            'playlist_id' => $playlistId,
            ...$timestamps,
        ]);
        $mapId = $this->insertScratchMap($userId, $epgId, $playlistId, $settings);

        foreach (array_chunk($data['epg'], 500) as $chunk) {
            DB::table('epg_channels')->insert(array_map(fn (array $row): array => [
                ...$row,
                'lang' => 'en',
                'epg_id' => $epgId,
                'user_id' => $userId,
                ...$timestamps,
            ], $chunk));
        }

        foreach (array_chunk($data['channels'], 500) as $chunk) {
            DB::table('channels')->insert(array_map(fn (array $channel): array => [
                'name' => $channel['name'],
                'title' => $channel['name'],
                'stream_id' => $channel['stream_id'],
                'source_id' => $channel['source_id'],
                'url' => 'http://benchmark.invalid/'.$channel['source_id'].'.ts',
                'group' => $name,
                'group_internal' => $name,
                'enabled' => true,
                'is_vod' => false,
                'epg_map_enabled' => true,
                'user_id' => $userId,
                'playlist_id' => $playlistId,
                'group_id' => $groupId,
                ...$timestamps,
            ], $chunk));
        }

        return [
            'map_id' => $mapId,
            'epg_id' => $epgId,
            'channel_ids' => DB::table('channels')->where('playlist_id', $playlistId)->orderBy('id')->pluck('id')->all(),
            'source_ids' => array_column($data['channels'], 'source_id'),
            'epg_channel_ids' => DB::table('epg_channels')->where('epg_id', $epgId)->pluck('channel_id', 'id')->all(),
            'expected' => collect($data['channels'])->mapWithKeys(fn (array $channel): array => [
                $channel['source_id'] => ['pattern' => $channel['pattern'], 'channel_id' => $channel['expected']],
            ])->all(),
        ];
    }

    /**
     * Point the benchmark at an existing map: the channels, EPG and settings
     * MapPlaylistChannelsToEpg uses for it, minus its "unmapped only" filter
     * so every run covers the same channels however many are already mapped.
     * Progress goes to a scratch copy of the map (rolled back with the rest),
     * so the real map is never locked or touched.
     *
     * @param  array<string, mixed>  $settings
     * @return array{map_id: int, epg_id: int, channel_ids: list<int>, source_ids: list<?string>, epg_channel_ids: array<int, string>, map_channels: int}
     */
    private function loadMap(EpgMap $map, int $channelLimit, array $settings): array
    {
        $channels = match (true) {
            (bool) $map->channels => Channel::query()->whereIn('id', $map->channels),
            (bool) $map->group_ids => Channel::query()->where('playlist_id', $map->playlist_id)->whereIn('group_id', $map->group_ids),
            default => Channel::query()->where('playlist_id', $map->playlist_id),
        };
        $channels = $channels->eligibleForEpgMapping();

        $selected = $channels->clone()->orderBy('id')->limit($channelLimit)->get(['id', 'source_id']);

        return [
            'map_id' => $this->insertScratchMap($map->user_id, $map->epg_id, $map->playlist_id, $settings),
            'epg_id' => $map->epg_id,
            'channel_ids' => $selected->pluck('id')->all(),
            'source_ids' => $selected->pluck('source_id')->all(),
            'epg_channel_ids' => Epg::query()->findOrFail($map->epg_id)->matchableChannels()->pluck('channel_id', 'id')->all(),
            'map_channels' => $channels->count(),
        ];
    }

    /**
     * Insert the map the chunk jobs report progress to, rolled back with
     * everything else the benchmark writes.
     *
     * @param  array<string, mixed>  $settings
     */
    private function insertScratchMap(int $userId, int $epgId, ?int $playlistId, array $settings): int
    {
        return DB::table('epg_maps')->insertGetId([
            'name' => 'EPG mapping benchmark',
            'uuid' => Str::uuid()->toString(),
            'user_id' => $userId,
            'epg_id' => $epgId,
            'playlist_id' => $playlistId,
            'settings' => json_encode($settings),
            'processing' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array{seconds: float, queries: int, query_seconds: float, peak_memory_bytes: int}>  $runs
     * @return array{median_seconds: float, min_seconds: float, max_seconds: float, spread_percent: float, median_queries: int, median_query_seconds: float, max_peak_memory_bytes: int}
     */
    private function summarize(array $runs): array
    {
        $runs = collect($runs);
        $median = (float) $runs->median('seconds');
        $min = (float) $runs->min('seconds');
        $max = (float) $runs->max('seconds');

        return [
            'median_seconds' => round($median, 4),
            'min_seconds' => $min,
            'max_seconds' => $max,
            'spread_percent' => $median > 0 ? round(($max - $min) / $median * 100, 1) : 0.0,
            'median_queries' => (int) $runs->median('queries'),
            'median_query_seconds' => round((float) $runs->median('query_seconds'), 4),
            'max_peak_memory_bytes' => (int) $runs->max('peak_memory_bytes'),
        ];
    }

    /**
     * Score the mappings against what the synthetic data was built to match.
     *
     * @param  array<string, array{pattern: string, channel_id: ?string}>  $expected
     * @param  array<string, ?string>  $mappings
     * @return array<string, array{channels: int, correct: int, wrong: int, unmapped: int}>
     */
    private function quality(array $expected, array $mappings): array
    {
        $quality = array_fill_keys(array_keys(self::PATTERNS), ['channels' => 0, 'correct' => 0, 'wrong' => 0, 'unmapped' => 0]);

        foreach ($expected as $sourceId => $channel) {
            $actual = $mappings[$sourceId] ?? null;
            $outcome = match (true) {
                $actual === null => 'unmapped',
                $actual === $channel['channel_id'] => 'correct',
                default => 'wrong',
            };

            $quality[$channel['pattern']]['channels']++;
            $quality[$channel['pattern']][$outcome]++;
        }

        return $quality;
    }

    /**
     * @param  array<string, mixed>  $results
     */
    private function report(array $results): void
    {
        $this->newLine();
        $this->table(
            ['Run', 'Time', 'Queries', 'Query time', 'Peak memory'],
            collect($results['runs'])->map(fn (array $run, int $index): array => [
                $index + 1,
                $this->seconds($run['seconds']),
                number_format($run['queries']),
                $this->seconds($run['query_seconds']),
                $this->megabytes($run['peak_memory_bytes']),
            ])->all(),
        );

        $summary = $results['summary'];
        $this->components->twoColumnDetail(
            'Median time',
            "{$this->seconds($summary['median_seconds'])} (min {$this->seconds($summary['min_seconds'])}, max {$this->seconds($summary['max_seconds'])}, spread {$summary['spread_percent']}%)",
        );
        $this->components->twoColumnDetail('Median queries', number_format($summary['median_queries']).' ('.$this->seconds($summary['median_query_seconds']).' in the database)');
        $this->components->twoColumnDetail('Mapped', number_format($results['mapped']).' of '.number_format(count($results['mappings'])).' channels');

        if ($results['quality'] !== null) {
            $this->newLine();
            $this->table(
                ['Channel pattern', 'Channels', 'Mapped correctly', 'Mapped wrong', 'Not mapped'],
                collect($results['quality'])->map(fn (array $counts, string $pattern): array => [
                    $pattern === 'unmatched' ? 'unmatched (should stay unmapped)' : $pattern,
                    $counts['channels'],
                    $counts['correct'],
                    $counts['wrong'],
                    $counts['unmapped'],
                ])->values()->all(),
            );
        }

        $this->components->twoColumnDetail('Mappings hash', $results['mappings_hash']);

        if ($results['query_shapes'] !== []) {
            $this->newLine();
            $this->table(
                ['Slowest queries in total (last run)', 'Count', 'Time', 'Average'],
                collect($results['query_shapes'])->map(fn (array $shape): array => [
                    Str::limit((string) preg_replace('/\s+/', ' ', $shape['sql']), 100),
                    number_format($shape['count']),
                    $this->seconds($shape['seconds']),
                    number_format($shape['seconds'] * 1000 / max(1, $shape['count']), 2).' ms',
                ])->all(),
            );
        }

        if (! $results['deterministic']) {
            $this->components->warn('The mappings changed between runs on identical data, so the matcher is not deterministic.');
        }

        foreach ($results['explain'] as $index => $query) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=yellow>Slow query '.($index + 1).' of '.count($results['explain']).' (warm-up run)</>', "{$query['time_ms']} ms");
            $this->line(Str::limit($query['sql'], 400));
            $this->newLine();
            foreach ($query['plan'] as $line) {
                $this->line('  '.$line);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $results
     */
    private function compare(array $baseline, array $results): void
    {
        $this->newLine();
        $this->components->info('Compared with '.($baseline['revision'] ?? 'the baseline'));

        $comparable = ['map', 'channels', 'epg_channels', 'seed', 'settings'];
        if (Arr::only($baseline['options'] ?? [], $comparable) != Arr::only($results['options'], $comparable)) {
            $this->components->warn('The baseline used a different --map, --channels, --epg-channels, --seed or settings, so these numbers are not directly comparable.');
        }

        $before = $baseline['summary'];
        $after = $results['summary'];
        $baselineQuality = $baseline['quality'] ?? null;
        $correct = fn (array $quality): int => collect($quality)->except('unmatched')->sum('correct');
        $falsePositives = fn (array $quality): int => $quality['unmatched']['wrong'] ?? 0;

        $summaryRow = fn (string $label, string $key, Closure $format): array => [
            $label,
            $format($before[$key]),
            $format($after[$key]),
            $this->percentChange($before[$key], $after[$key]),
        ];
        $countRow = fn (string $label, int $baselineCount, int $count): array => [
            $label,
            $baselineCount,
            $count,
            sprintf('%+d', $count - $baselineCount),
        ];

        $rows = [
            $summaryRow('Median time', 'median_seconds', $this->seconds(...)),
            $summaryRow('Queries', 'median_queries', number_format(...)),
            $summaryRow('Query time', 'median_query_seconds', $this->seconds(...)),
            $summaryRow('Peak memory', 'max_peak_memory_bytes', $this->megabytes(...)),
            $countRow('Mapped', $baseline['mapped'] ?? 0, $results['mapped']),
        ];

        if ($baselineQuality !== null && $results['quality'] !== null) {
            $rows[] = $countRow('Mapped correctly', $correct($baselineQuality), $correct($results['quality']));
            $rows[] = $countRow('False matches', $falsePositives($baselineQuality), $falsePositives($results['quality']));
        }

        $this->table(['', 'Baseline', 'This run', 'Change'], $rows);

        if ($before['median_seconds'] > 0 && $after['median_seconds'] / $before['median_seconds'] >= 1.2) {
            $this->components->warn('This run is '.$this->percentChange($before['median_seconds'], $after['median_seconds']).' slower than the baseline. Re-run both to rule out noise before drawing conclusions.');
        }

        $differences = collect($results['mappings'])
            ->union($baseline['mappings'])
            ->keys()
            ->filter(fn (string $sourceId): bool => ($baseline['mappings'][$sourceId] ?? null) !== ($results['mappings'][$sourceId] ?? null))
            ->values();

        if ($differences->isEmpty()) {
            $this->components->info('Mappings are identical to the baseline.');

            return;
        }

        $this->components->warn("{$differences->count()} channel(s) mapped differently from the baseline:");
        foreach ($differences->take(10) as $sourceId) {
            $this->components->twoColumnDetail(
                $sourceId,
                ($baseline['mappings'][$sourceId] ?? 'not mapped').' -> '.($results['mappings'][$sourceId] ?? 'not mapped'),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(): array
    {
        $connection = DB::connection();
        $environment = [
            'php' => PHP_VERSION,
            'driver' => $connection->getDriverName(),
            'server_version' => $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
        ];

        if ($environment['driver'] === 'pgsql') {
            $environment['pg_trgm'] = DB::table('pg_extension')->where('extname', 'pg_trgm')->exists();
            $environment['trigram_indexes'] = DB::table('pg_indexes')
                ->where('tablename', 'epg_channels')
                ->where('indexname', 'like', '%_trgm')
                ->count();
            $environment['similarity_threshold'] = DB::selectOne("SELECT current_setting('pg_trgm.similarity_threshold', true) AS threshold")?->threshold;
        }

        return $environment;
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    private function describeEnvironment(array $environment): string
    {
        $description = "{$environment['driver']} {$environment['server_version']}";

        if ($environment['driver'] !== 'pgsql') {
            return $description;
        }

        if (! $environment['pg_trgm']) {
            return "{$description} (pg_trgm not installed)";
        }

        $threshold = $environment['similarity_threshold'] ?: 'default';

        return "{$description} (pg_trgm, {$environment['trigram_indexes']} trigram indexes, threshold {$threshold})";
    }

    /** Short commit hash, suffixed with "-dirty" when there are uncommitted changes. */
    private function revision(): ?string
    {
        try {
            $commit = Process::path(base_path())->run(['git', 'rev-parse', '--short', 'HEAD']);
            if (! $commit->successful()) {
                return null;
            }

            $dirty = trim(Process::path(base_path())->run(['git', 'status', '--porcelain', '--untracked-files=no'])->output()) !== '';

            return trim($commit->output()).($dirty ? '-dirty' : '');
        } catch (Throwable) {
            return null;
        }
    }

    private function seconds(float $seconds): string
    {
        return number_format($seconds, 2).'s';
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1).' MB';
    }

    private function percentChange(float $before, float $after): string
    {
        if ($before <= 0) {
            return 'n/a';
        }

        return sprintf('%+.1f%%', ($after - $before) / $before * 100);
    }
}
