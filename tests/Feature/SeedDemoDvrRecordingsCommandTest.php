<?php

declare(strict_types=1);

use App\Enums\DvrRecordingStatus;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\DvrMetadataEnricherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Storage::fake('dvr');

    // Stand in for ffmpeg: write a placeholder clip to the output path (last argument).
    Process::fake(function (PendingProcess $process) {
        file_put_contents(end($process->command), 'demo-clip');

        return Process::result();
    });

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->setting = DvrSetting::factory()->enabled()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'dvr_output_format' => 'ts',
    ]);

    $epg = Epg::factory()->for($this->user)->create();
    $epgChannel = EpgChannel::factory()->create([
        'epg_id' => $epg->id,
        'user_id' => $this->user->id,
        'channel_id' => 'demo.channel',
    ]);
    $this->channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
    ]);

    $airing = fn (string $title, int $hoursAgo, ?int $season = null, ?int $episode = null) => EpgProgramme::factory()->create([
        'epg_id' => $epg->id,
        'epg_channel_id' => 'demo.channel',
        'title' => $title,
        'season' => $season,
        'episode' => $episode,
        'start_time' => now()->subHours($hoursAgo),
        'end_time' => now()->subHours($hoursAgo)->addMinutes(30),
    ]);

    $airing('Demo Series', 10, 1, 1);
    $airing('Demo Series', 9, 1, 2);
    $airing('Demo Series', 8, 1, 3);
    $airing('Evening News', 5);
    $airing('Late Movie', 3);
    // Still airing: never seeded as a completed recording.
    $airing('Live Now', 0);
});

it('seeds completed recordings with files from past programmes', function () {
    $this->artisan('dvr:seed-demo', ['--count' => 5, '--no-integrate' => true])->assertSuccessful();

    $recordings = DvrRecording::orderBy('id')->get();

    expect($recordings)->toHaveCount(5)
        ->and($recordings->pluck('title')->unique()->sort()->values()->all())->toBe(['Demo Series', 'Evening News', 'Late Movie'])
        ->and($recordings->where('title', 'Demo Series')->pluck('episode')->sort()->values()->all())->toBe([1, 2, 3]);

    $recordings->each(function (DvrRecording $recording) {
        expect($recording->status)->toBe(DvrRecordingStatus::Completed)
            ->and($recording->channel_id)->toBe($this->channel->id)
            ->and($recording->metadata['demo_seed'] ?? false)->toBeTrue()
            ->and($recording->file_path)->toStartWith('library/')
            ->and(Storage::disk('dvr')->exists($recording->file_path))->toBeTrue();
    });

    $first = $recordings->first();
    $edlPath = pathinfo($first->file_path, PATHINFO_DIRNAME).'/'.pathinfo($first->file_path, PATHINFO_FILENAME).'.edl';
    expect(Storage::disk('dvr')->get($edlPath))->toBe("30.00\t60.00\t0\n");
});

it('matches each recording like a finished one before adding it to the library', function () {
    // Stand in for the TMDB/TVMaze lookup: only "Demo Series" is known.
    $this->mock(DvrMetadataEnricherService::class, function ($enricher) {
        $enricher->shouldReceive('enrich')->times(5)->andReturnUsing(function (DvrRecording $recording) {
            $recording->update(['post_processing_step' => 'Fetching TVMaze metadata']);
            if ($recording->title === 'Demo Series') {
                $recording->update(['metadata' => [...$recording->metadata, 'tvmaze' => ['id' => 7, 'name' => 'Demo Series (Matched)']]]);
            }
        });
    });

    $this->artisan('dvr:seed-demo', ['--count' => 5])->assertSuccessful();

    // Whether the unmatched one-offs become movies or series depends on the
    // factory's random EPG data, so only the matched show is checked.
    $seriesNames = Series::where('import_batch_no', 'dvr')->pluck('name');
    expect($seriesNames)->toContain('Demo Series (Matched)')->not->toContain('Demo Series')
        ->and(Episode::whereHas('series', fn ($series) => $series->where('name', 'Demo Series (Matched)'))->count())->toBe(3);

    DvrRecording::all()->each(fn (DvrRecording $recording) => expect($recording->metadata['demo_seed'] ?? false)->toBeTrue()
        ->and($recording->post_processing_step)->toBeNull());
});

it('purges only seeded demo recordings', function () {
    $this->artisan('dvr:seed-demo', ['--count' => 2, '--no-integrate' => true])->assertSuccessful();
    $real = DvrRecording::factory()->for($this->user)->create(['dvr_setting_id' => $this->setting->id]);
    $seededPaths = DvrRecording::whereKeyNot($real->id)->pluck('file_path');

    $this->artisan('dvr:seed-demo', ['--purge' => true])->assertSuccessful();

    expect(DvrRecording::pluck('id')->all())->toBe([$real->id]);
    $seededPaths->each(fn (string $path) => expect(Storage::disk('dvr')->exists($path))->toBeFalse());
});

it('refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('dvr:seed-demo')->assertFailed();

    expect(DvrRecording::count())->toBe(0);
});
