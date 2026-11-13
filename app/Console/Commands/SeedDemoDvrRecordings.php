<?php

namespace App\Console\Commands;

use App\Enums\DvrRecordingStatus;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\EpgProgramme;
use App\Models\PlaylistAuth;
use App\Services\DvrMetadataEnricherService;
use App\Services\DvrPostProcessorService;
use App\Services\DvrVodIntegrationService;
use App\Support\SeriesKey;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Local development helper: stages completed DVR recordings built from real
 * EPG programmes that already aired on the DVR playlist's channels, each
 * backed by a short generated test clip so playback, search and the VOD/Series
 * integration can be exercised without waiting on real recordings.
 *
 * Every seeded row is tagged with `metadata.demo_seed`, which is what
 * `--purge` uses to remove them again (through the model's normal delete
 * cascade, so files, EDL sidecars and VOD/Series rows go too).
 */
class SeedDemoDvrRecordings extends Command
{
    /**
     * @var string
     */
    protected $signature = 'dvr:seed-demo
                            {--setting= : DVR setting ID to seed (defaults to the first enabled setting owned by a playlist)}
                            {--count=10 : Number of recordings to create}
                            {--playlist-auth= : Playlist auth ID or username to own the recordings, or "none" (defaults to the one the setting\'s existing recordings use)}
                            {--duration=120 : Length of each generated test clip, in seconds}
                            {--no-integrate : Skip adding the recordings to the VOD/Series library}
                            {--purge : Remove all previously seeded demo recordings and exit}';

    /**
     * @var string
     */
    protected $description = 'Local development only: seed completed demo DVR recordings from real past EPG programmes';

    /**
     * Commercial block written to the EDL sidecar of every other recording, in
     * seconds, so comskip skipping can be tested. Only used when the clip is
     * long enough to contain it.
     */
    private const EDL_COMMERCIAL_START = 30;

    private const EDL_COMMERCIAL_END = 60;

    public function handle(DvrPostProcessorService $postProcessor, DvrMetadataEnricherService $enricher, DvrVodIntegrationService $vodIntegration): int
    {
        if (app()->isProduction()) {
            $this->error('dvr:seed-demo is a local development helper and refuses to run in production.');

            return self::FAILURE;
        }

        if ($this->option('purge')) {
            return $this->purge();
        }

        $setting = $this->resolveSetting();
        if (! $setting) {
            $this->error('No enabled DVR setting owned by a playlist was found. Pass --setting=<id>.');

            return self::FAILURE;
        }

        $playlistAuthId = $this->resolvePlaylistAuthId($setting);
        if ($playlistAuthId === false) {
            return self::FAILURE;
        }

        $count = max(1, (int) $this->option('count'));
        $duration = max(10, (int) $this->option('duration'));

        $programmes = $this->pickProgrammes($setting, $count);
        if ($programmes->isEmpty()) {
            $this->error("No past EPG programmes found on the enabled, EPG-mapped live channels of playlist {$setting->playlist_id}.");

            return self::FAILURE;
        }

        $disk = $setting->storage_disk ?: config('dvr.storage_disk', 'local');
        $format = $setting->dvr_output_format ?? 'mp4';

        $templatePath = $this->generateClip($format, $duration);
        if ($templatePath === null) {
            return self::FAILURE;
        }

        $this->info("Seeding {$programmes->count()} demo recording(s) for DVR setting {$setting->id} (playlist {$setting->playlist_id}, playlist auth ".($playlistAuthId ?? 'none').')...');

        try {
            foreach ($programmes->values() as $index => $programme) {
                $recording = $this->createRecording($setting, $programme, $playlistAuthId, $duration);

                $path = $postProcessor->reserveOutputPath($recording, $disk);
                copy($templatePath, Storage::disk($disk)->path($path));
                $recording->update(['file_size_bytes' => Storage::disk($disk)->size($path)]);

                $withEdl = $index % 2 === 0 && $duration > self::EDL_COMMERCIAL_END;
                if ($withEdl) {
                    $edlPath = pathinfo($path, PATHINFO_DIRNAME).'/'.pathinfo($path, PATHINFO_FILENAME).'.edl';
                    Storage::disk($disk)->put($edlPath, sprintf("%.2f\t%.2f\t0\n", self::EDL_COMMERCIAL_START, self::EDL_COMMERCIAL_END));
                }

                if (! $this->option('no-integrate')) {
                    try {
                        // The same steps a finished recording goes through
                        // (EnrichDvrMetadata, then IntegrateDvrRecordingToVod),
                        // run inline: a TMDB/TVMaze match first, so the library
                        // item starts with provider art like a real recording.
                        $enricher->enrich($recording);
                        $recording->refresh()->update(['post_processing_step' => null]);
                        $vodIntegration->integrateRecording($recording);
                    } catch (Throwable $e) {
                        $this->warn("  VOD integration failed for [{$recording->id}]: {$e->getMessage()}");
                    }
                }

                $label = $recording->season !== null && $recording->episode !== null
                    ? sprintf('%s S%02dE%02d', $recording->title, $recording->season, $recording->episode)
                    : $recording->title;
                $this->line("  [{$recording->id}] {$label}".($withEdl ? ' (with EDL)' : ''));
            }
        } finally {
            @unlink($templatePath);
        }

        $this->info('Done. Refresh content in M3U TV to see them. Remove them with: php artisan dvr:seed-demo --purge');

        return self::SUCCESS;
    }

    private function purge(): int
    {
        $query = DvrRecording::where('metadata->demo_seed', true);
        $total = $query->count();

        $query->lazyById()->each(fn (DvrRecording $recording) => $recording->delete());

        $this->info("Removed {$total} demo recording(s).");

        return self::SUCCESS;
    }

    private function resolveSetting(): ?DvrSetting
    {
        if ($this->option('setting')) {
            return DvrSetting::find((int) $this->option('setting'));
        }

        return DvrSetting::where('enabled', true)
            ->whereNotNull('playlist_id')
            ->orderBy('id')
            ->first();
    }

    /**
     * The guest the recordings belong to. The Xtream API only lists a guest's
     * own recordings, so they must match the account M3U TV signs in with.
     *
     * @return int|null|false the PlaylistAuth id, null for none, or false when
     *                        an explicitly requested one was not found
     */
    private function resolvePlaylistAuthId(DvrSetting $setting): int|null|false
    {
        $option = $this->option('playlist-auth');

        if ($option === null) {
            return DvrRecording::where('dvr_setting_id', $setting->id)
                ->whereNotNull('playlist_auth_id')
                ->selectRaw('playlist_auth_id, count(*) as uses')
                ->groupBy('playlist_auth_id')
                ->orderByDesc('uses')
                ->value('playlist_auth_id');
        }

        if ($option === 'none') {
            return null;
        }

        $auth = ctype_digit((string) $option)
            ? PlaylistAuth::find((int) $option)
            : PlaylistAuth::where('username', $option)->first();

        if (! $auth) {
            $this->error("Playlist auth '{$option}' not found.");

            return false;
        }

        return $auth->id;
    }

    /**
     * Picks a varied set of programmes that aired in the last three days: up to
     * three episodes of one series (to exercise season grouping), then single
     * series episodes and one-off programmes in turn, each with a distinct title.
     *
     * @return Collection<int, EpgProgramme>
     */
    private function pickProgrammes(DvrSetting $setting, int $count): Collection
    {
        $candidates = EpgProgramme::query()
            ->join('epg_channels', function ($join): void {
                $join->on('epg_channels.epg_id', '=', 'epg_programmes.epg_id')
                    ->on('epg_channels.channel_id', '=', 'epg_programmes.epg_channel_id');
            })
            ->join('channels', 'channels.epg_channel_id', '=', 'epg_channels.id')
            ->where('channels.playlist_id', $setting->playlist_id)
            ->where('channels.enabled', true)
            ->where('channels.is_vod', false)
            ->where('epg_programmes.end_time', '<', now())
            ->where('epg_programmes.start_time', '>', now()->subDays(3))
            ->inRandomOrder()
            ->limit(500)
            ->get(['epg_programmes.*', 'channels.id as source_channel_id'])
            ->filter(fn (EpgProgramme $programme): bool => filled($programme->title)
                && $programme->start_time->diffInMinutes($programme->end_time) >= 15);

        $isEpisode = fn (EpgProgramme $programme): bool => $programme->season !== null && $programme->episode !== null;

        $picked = collect();
        $usedTitles = [];

        $multiEpisode = $candidates->filter($isEpisode)
            ->groupBy('title')
            ->map(fn (Collection $airings) => $airings->unique(fn (EpgProgramme $programme) => $programme->season.'x'.$programme->episode))
            ->first(fn (Collection $airings) => $airings->count() >= 2);

        if ($multiEpisode && $count >= 3) {
            $multiEpisode->sortBy(['season', 'episode'])->take(3)->each(fn (EpgProgramme $programme) => $picked->push($programme));
            $usedTitles[$multiEpisode->first()->title] = true;
        }

        $pools = [
            $candidates->filter($isEpisode)->values(),
            $candidates->reject($isEpisode)->values(),
        ];
        $cursors = [0, 0];
        $pool = 0;

        while ($picked->count() < $count && ($cursors[0] < $pools[0]->count() || $cursors[1] < $pools[1]->count())) {
            if ($cursors[$pool] >= $pools[$pool]->count()) {
                $pool = 1 - $pool;
            }

            $programme = $pools[$pool][$cursors[$pool]++];
            if (! isset($usedTitles[$programme->title])) {
                $usedTitles[$programme->title] = true;
                $picked->push($programme);
                $pool = 1 - $pool;
            }
        }

        return $picked->take($count);
    }

    private function createRecording(DvrSetting $setting, EpgProgramme $programme, ?int $playlistAuthId, int $duration): DvrRecording
    {
        $startEarly = (int) ($setting->default_start_early_seconds ?? 0);
        $endLate = (int) ($setting->default_end_late_seconds ?? 0);

        return DvrRecording::create([
            'user_id' => $setting->user_id,
            'dvr_setting_id' => $setting->id,
            'playlist_auth_id' => $playlistAuthId,
            'channel_id' => $programme->source_channel_id,
            'status' => DvrRecordingStatus::Completed,
            'title' => $programme->title,
            'series_key' => SeriesKey::for($setting->id, $programme->title),
            'normalized_title' => SeriesKey::normalize($programme->title) ?: null,
            'subtitle' => $programme->subtitle,
            'description' => $programme->description,
            'season' => $programme->season,
            'episode' => $programme->episode,
            'scheduled_start' => $programme->start_time->copy()->subSeconds($startEarly),
            'scheduled_end' => $programme->end_time->copy()->addSeconds($endLate),
            'actual_start' => $programme->start_time,
            'actual_end' => $programme->end_time,
            'duration_seconds' => $duration,
            'programme_start' => $programme->start_time,
            'programme_end' => $programme->end_time,
            'attempt_count' => 1,
            'metadata' => ['demo_seed' => true],
            'epg_programme_data' => [
                'epg_id' => $programme->epg_id,
                'epg_channel_id' => $programme->epg_channel_id,
                'episode_num' => $programme->episode_num,
                'category' => $programme->category,
                'icon' => $programme->icon,
                'rating' => $programme->rating,
                'is_new' => $programme->is_new,
            ],
        ]);
    }

    /**
     * Renders one test-pattern clip (moving pattern, running timer, tone) that
     * every seeded recording gets a copy of.
     */
    private function generateClip(string $format, int $duration): ?string
    {
        $path = sys_get_temp_dir().'/m3ue-dvr-demo-'.uniqid().'.'.$format;

        $command = [
            config('dvr.ffmpeg_path', 'ffmpeg'), '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'testsrc2=size=1280x720:rate=30',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
            '-t', (string) $duration,
            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k',
            ...($format === 'mp4' ? ['-movflags', '+faststart'] : []),
            $path,
        ];

        $this->info("Generating a {$duration}s test clip with ffmpeg...");
        $result = Process::timeout(600)->run($command);

        if (! $result->successful() || ! is_file($path)) {
            $this->error('ffmpeg failed to generate the test clip: '.trim($result->errorOutput() ?: $result->output()));

            return null;
        }

        return $path;
    }
}
