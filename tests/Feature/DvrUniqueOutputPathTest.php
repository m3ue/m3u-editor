<?php

/**
 * DVR output paths must be unique per recording.
 *
 * buildOutputPath() names non-episodic recordings "<title> - <Y-m-d>.<ext>" and
 * episodic ones "<title> - SxxEyy.<ext>", so two recordings of the same programme on
 * the same day (or a repeat of the same episode) used to post-process into the same
 * file: the later one silently replaced the earlier and both rows pointed at it.
 */

declare(strict_types=1);

use App\Enums\DvrRecordingStatus;
use App\Jobs\PostProcessDvrRecording;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Services\DvrPostProcessorService;
use App\Services\DvrRetentionService;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('dvr');
    Queue::fake();
    Http::preventStrayRequests();
    $this->setting = DvrSetting::factory()->create(['dvr_output_format' => 'mkv', 'enable_metadata_enrichment' => false]);
    $this->service = app(DvrPostProcessorService::class);
});

function sameDayRecording(DvrSetting $setting, string $time, array $overrides = []): DvrRecording
{
    $start = Carbon::parse("2026-11-03 {$time}", 'UTC');

    return DvrRecording::factory()->create(array_merge([
        'user_id' => $setting->user_id,
        'dvr_setting_id' => $setting->id,
        'title' => 'Match of the Day',
        'season' => null,
        'episode' => null,
        'scheduled_start' => $start,
        'scheduled_end' => $start->copy()->addHour(),
        'programme_start' => $start,
        'programme_end' => $start->copy()->addHour(),
        'status' => DvrRecordingStatus::PostProcessing,
    ], $overrides));
}

it('gives two same-title recordings on the same day different files', function (): void {
    $first = sameDayRecording($this->setting, '15:00');
    $second = sameDayRecording($this->setting, '20:00');

    $a = $this->service->reserveOutputPath($first, 'dvr');
    $b = $this->service->reserveOutputPath($second, 'dvr');

    expect($a)->toBe('library/2026/Match of the Day/Match of the Day - 2026-11-03.mkv')
        ->and($b)->toBe('library/2026/Match of the Day/Match of the Day - 2026-11-03 (2).mkv')
        ->and($first->fresh()->file_path)->toBe($a)
        ->and($second->fresh()->file_path)->toBe($b);
    Storage::disk('dvr')->assertExists([$a, $b]);
});

it('gives a repeat of the same season/episode its own file', function (): void {
    $first = sameDayRecording($this->setting, '15:00', ['season' => 2, 'episode' => 5]);
    $repeat = sameDayRecording($this->setting, '23:00', ['season' => 2, 'episode' => 5]);

    $a = $this->service->reserveOutputPath($first, 'dvr');
    $b = $this->service->reserveOutputPath($repeat, 'dvr');

    expect($a)->toEndWith('Match of the Day - S02E05.mkv')
        ->and($b)->toEndWith('Match of the Day - S02E05 (2).mkv');
});

it('never overwrites a file that already exists on disk', function (): void {
    $base = 'library/2026/Match of the Day/Match of the Day - 2026-11-03.mkv';
    Storage::disk('dvr')->put($base, 'an existing recording nobody references');

    $path = $this->service->reserveOutputPath(sameDayRecording($this->setting, '15:00'), 'dvr');

    expect($path)->toEndWith('2026-11-03 (2).mkv')
        ->and(Storage::disk('dvr')->get($base))->toBe('an existing recording nobody references');
});

it('does not reuse a path another recording row points at, even if its file is missing', function (): void {
    $base = 'library/2026/Match of the Day/Match of the Day - 2026-11-03.mkv';
    sameDayRecording($this->setting, '09:00', ['status' => DvrRecordingStatus::Completed, 'file_path' => $base]);

    $path = $this->service->reserveOutputPath(sameDayRecording($this->setting, '15:00'), 'dvr');

    expect($path)->toEndWith('2026-11-03 (2).mkv');
});

it('reuses its own reservation when post-processing is retried', function (): void {
    $recording = sameDayRecording($this->setting, '15:00');

    $first = $this->service->reserveOutputPath($recording, 'dvr');
    $again = $this->service->reserveOutputPath($recording->fresh(), 'dvr');

    expect($again)->toBe($first)
        ->and(Storage::disk('dvr')->allFiles('library'))->toHaveCount(1);
});

it('keeps the existing name format for the first recording of a day', function (): void {
    $recording = sameDayRecording($this->setting, '15:00');

    expect($this->service->reserveOutputPath($recording, 'dvr'))
        ->toBe($this->service->buildOutputPath($recording));
});

// --- end to end: real FFmpeg concat of two same-day recordings ------------------------

function writeFakeCapture(DvrRecording $recording, int $frequency): void
{
    $dir = Storage::disk('dvr')->path('live/'.$recording->uuid);
    @mkdir($dir, 0755, true);
    exec(sprintf(
        'ffmpeg -v error -y -f lavfi -i testsrc2=size=160x90:rate=25 -f lavfi -i sine=frequency=%d -t 6 '
        .'-c:v libx264 -preset ultrafast -g 25 -c:a aac -f hls -hls_time 2 -hls_list_size 0 '
        .'-hls_segment_filename %s %s 2>&1',
        $frequency,
        escapeshellarg($dir.'/live%06d.ts'),
        escapeshellarg($dir.'/live.m3u8'),
    ), $out, $code);
    expect($code)->toBe(0);
}

it('post-processes two same-day recordings into two separate, complete files', function (): void {
    if (! is_executable('/usr/bin/ffmpeg')) {
        $this->markTestSkipped('ffmpeg required');
    }
    config(['dvr.ffmpeg_path' => '/usr/bin/ffmpeg']);

    $afternoon = sameDayRecording($this->setting, '15:00');
    $evening = sameDayRecording($this->setting, '20:00');
    writeFakeCapture($afternoon, 440);
    writeFakeCapture($evening, 880);

    $this->service->run($afternoon->fresh());
    $this->service->run($evening->fresh());

    $afternoon->refresh();
    $evening->refresh();

    expect($afternoon->status)->toBe(DvrRecordingStatus::Completed)
        ->and($evening->status)->toBe(DvrRecordingStatus::Completed)
        ->and($afternoon->file_path)->not->toBe($evening->file_path)
        ->and(Storage::disk('dvr')->exists($afternoon->file_path))->toBeTrue()
        ->and(Storage::disk('dvr')->exists($evening->file_path))->toBeTrue()
        ->and($afternoon->file_size_bytes)->toBe(Storage::disk('dvr')->size($afternoon->file_path))
        ->and($evening->file_size_bytes)->toBe(Storage::disk('dvr')->size($evening->file_path));
});

it('releases an empty reservation when the concat fails', function (): void {
    config(['dvr.ffmpeg_path' => '/bin/false']);
    $recording = sameDayRecording($this->setting, '15:00');
    Storage::disk('dvr')->put("live/{$recording->uuid}/live.m3u8", "#EXTM3U\n#EXTINF:2.0,\nlive000000.ts\n");
    Storage::disk('dvr')->put("live/{$recording->uuid}/live000000.ts", 'not video');

    $this->service->run($recording->fresh());

    $recording->refresh();
    expect($recording->status)->toBe(DvrRecordingStatus::Failed)
        ->and($recording->file_path)->toBeNull()
        ->and(Storage::disk('dvr')->allFiles('library'))->toBe([]);
});

// --- recordings that already share one file (made before this fix) -------------------

it('gives a reprocessed recording its own file instead of overwriting a legacy file it shares', function (): void {
    if (! is_executable('/usr/bin/ffmpeg')) {
        $this->markTestSkipped('ffmpeg required');
    }
    config(['dvr.ffmpeg_path' => '/usr/bin/ffmpeg']);
    $shared = 'library/2026/Match of the Day/Match of the Day - 2026-11-03.mkv';
    Storage::disk('dvr')->put($shared, 'the other recording\'s video');
    $other = sameDayRecording($this->setting, '15:00', ['status' => DvrRecordingStatus::Completed, 'file_path' => $shared]);
    $reprocessed = sameDayRecording($this->setting, '20:00', ['file_path' => $shared]); // PostProcessing again
    writeFakeCapture($reprocessed, 880);

    $this->service->run($reprocessed->fresh());

    expect(Storage::disk('dvr')->get($shared))->toBe('the other recording\'s video')
        ->and($other->fresh()->file_path)->toBe($shared)
        ->and($reprocessed->fresh()->file_path)->not->toBe($shared)
        ->and($reprocessed->fresh()->status)->toBe(DvrRecordingStatus::Completed);
    Storage::disk('dvr')->assertExists($reprocessed->fresh()->file_path);
});

it('keeps a shared legacy file when one of the recordings using it is deleted', function (): void {
    $shared = 'library/2026/Match of the Day/Match of the Day - 2026-11-03.mkv';
    Storage::disk('dvr')->put($shared, 'video');
    $older = sameDayRecording($this->setting, '15:00', ['status' => DvrRecordingStatus::Completed, 'file_path' => $shared]);
    $newer = sameDayRecording($this->setting, '20:00', ['status' => DvrRecordingStatus::Completed, 'file_path' => $shared]);

    $older->delete();
    Storage::disk('dvr')->assertExists($shared);

    $newer->delete();
    Storage::disk('dvr')->assertMissing($shared);
});

it('retention purges a recording without deleting a file another recording still uses', function (): void {
    $this->setting->update(['enabled' => true, 'retention_days' => 7]);
    $shared = 'library/2026/Match of the Day/Match of the Day - 2026-11-03.mkv';
    Storage::disk('dvr')->put($shared, 'video');
    $old = sameDayRecording($this->setting, '15:00', [
        'status' => DvrRecordingStatus::Completed, 'file_path' => $shared, 'actual_end' => now()->subDays(30),
    ]);
    $recent = sameDayRecording($this->setting, '20:00', [
        'status' => DvrRecordingStatus::Completed, 'file_path' => $shared, 'actual_end' => now()->subDay(),
    ]);

    app(DvrRetentionService::class)->runForSetting($this->setting->fresh());

    expect($old->fresh()->status)->toBe(DvrRecordingStatus::Purged)
        ->and($recent->fresh()->status)->toBe(DvrRecordingStatus::Completed);
    Storage::disk('dvr')->assertExists($shared);
});

// --- one post-processing attempt per recording at a time -----------------------------

function runThroughMiddleware(PostProcessDvrRecording $job): void
{
    $middleware = method_exists($job, 'middleware') ? $job->middleware() : [];
    $next = fn (PostProcessDvrRecording $j) => $j->handle(app(DvrPostProcessorService::class));
    $pipeline = array_reduce(array_reverse($middleware), fn ($carry, $m) => fn ($j) => $m->handle($j, $carry), $next);
    $pipeline($job);
}

it('drops a second post-processing attempt for a recording that is already being processed', function (): void {
    $recording = sameDayRecording($this->setting, '15:00');
    $runs = 0;
    $this->mock(DvrPostProcessorService::class, function ($mock) use (&$runs): void {
        $mock->shouldReceive('run')->andReturnUsing(function () use (&$runs): void {
            $runs++;
        });
    });

    $running = new PostProcessDvrRecording($recording->id);
    $lock = Cache::lock((new WithoutOverlapping((string) $recording->id))->getLockKey($running), 600);
    $lock->forceRelease();
    expect($lock->get())->toBeTrue(); // the first attempt is still running

    try {
        $duplicate = (new PostProcessDvrRecording($recording->id))->withFakeQueueInteractions();
        runThroughMiddleware($duplicate);

        expect($runs)->toBe(0);
        $duplicate->assertNotReleased(); // skipped, not re-queued: the running attempt does the work
    } finally {
        $lock->forceRelease();
    }
});

it('runs the attempt once when no other is running, and frees the lock afterwards', function (): void {
    $recording = sameDayRecording($this->setting, '15:00');
    $runs = 0;
    $this->mock(DvrPostProcessorService::class, function ($mock) use (&$runs): void {
        $mock->shouldReceive('run')->andReturnUsing(function () use (&$runs): void {
            $runs++;
        });
    });
    $job = (new PostProcessDvrRecording($recording->id))->withFakeQueueInteractions();
    $lock = Cache::lock((new WithoutOverlapping((string) $recording->id))->getLockKey($job), 600);
    $lock->forceRelease();

    try {
        runThroughMiddleware($job);

        expect($runs)->toBe(1);
        expect($lock->get())->toBeTrue(); // released again once the attempt finished
    } finally {
        $lock->forceRelease();
    }
});
