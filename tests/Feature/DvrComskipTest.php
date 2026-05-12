<?php

/**
 * Tests for DVR Comskip (commercial detection) integration:
 *  — shouldRunComskip() tri-state resolution (rule → setting fallback)
 *  — resolveComskipIniPath() (custom ini vs bundled default)
 *  — runComskip() skips when disabled, runs when enabled
 *  — Non-fatal: recording completes even when comskip fails
 *  — .edl file is produced as sidecar next to media file
 */

use App\Enums\DvrRecordingStatus;
use App\Jobs\ProcessComskipOnRecording;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use App\Services\DvrHlsDownloaderService;
use App\Services\DvrPostProcessorService;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeComskipRecording(
    array $recordingOverrides = [],
    array $settingOverrides = [],
    ?array $ruleOverrides = null,
): DvrRecording {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create(array_merge([
        'storage_disk' => 'dvr-test',
        'use_proxy' => false,
        'enable_comskip' => true,
    ], $settingOverrides));

    $rule = null;
    if ($ruleOverrides !== null) {
        $rule = DvrRecordingRule::factory()
            ->for($user)
            ->for($setting)
            ->create($ruleOverrides);
    }

    return DvrRecording::factory()
        ->for($user)
        ->for($setting)
        ->state(['dvr_recording_rule_id' => $rule?->id])
        ->completed()
        ->create($recordingOverrides);
}

beforeEach(function () {
    Storage::fake('dvr-test');
    Event::fake();

    // Create a fake comskip binary that simulates success by creating the .edl file
    $fakeBinary = Storage::disk('dvr-test')->path('fake-comskip.sh');
    $script = <<<'SCRIPT'
#!/bin/sh
# Fake comskip: creates the .edl sidecar with placeholder content
output_dir=""
video=""
while [ $# -gt 0 ]; do
    case "$1" in
        --output=*) output_dir="${1#--output=}" ;;
        *) video="$1" ;;
    esac
    shift
done
edl=$(echo "$video" | sed 's/\.[^.]*$/.edl/')
cat > "$edl" <<'EDL'
1.00 120.00 0
300.00 360.00 0
600.00 630.00 0
EDL
exit 0
SCRIPT;
    file_put_contents($fakeBinary, $script);
    chmod($fakeBinary, 0755);

    config()->set('dvr.comskip_path', $fakeBinary);
    config()->set('dvr.comskip_default_ini', config_path('comskip.default.ini'));
});

// ── shouldRunComskip() tri-state resolution ─────────────────────────────────

it('returns true when DvrSetting enable_comskip is true and rule has null', function () {
    $recording = makeComskipRecording(
        ['file_path' => 'library/2025/Show/Show - S01E01.ts'],
    );

    expect($recording->shouldRunComskip())->toBeTrue();
});

it('returns false when DvrSetting enable_comskip is false', function () {
    $recording = makeComskipRecording(
        ['file_path' => 'library/2025/Show/Show - S01E01.ts'],
        ['enable_comskip' => false],
    );

    expect($recording->shouldRunComskip())->toBeFalse();
});

it('rule enable_comskip=true overrides DvrSetting enable_comskip=false', function () {
    $recording = makeComskipRecording(
        ['file_path' => 'library/2025/Show/Show - S01E01.ts'],
        ['enable_comskip' => false],
        ['enable_comskip' => 1],
    );

    expect($recording->shouldRunComskip())->toBeTrue();
});

it('rule enable_comskip=false overrides DvrSetting enable_comskip=true', function () {
    $recording = makeComskipRecording(
        ['file_path' => 'library/2025/Show/Show - S01E01.ts'],
        ['enable_comskip' => true],
        ['enable_comskip' => 0],
    );

    expect($recording->shouldRunComskip())->toBeFalse();
});

// ── resolveComskipIniPath() ─────────────────────────────────────────────────

it('returns bundled default ini when no custom ini is set', function () {
    $recording = makeComskipRecording();

    expect($recording->resolveComskipIniPath())->toBe(config_path('comskip.default.ini'));
});

it('returns custom ini path when DvrSetting has one', function () {
    $customIniPath = 'custom/comskip.ini';
    Storage::disk('dvr-test')->put($customIniPath, '[settings]');

    $recording = makeComskipRecording(
        settingOverrides: ['comskip_ini_path' => $customIniPath],
    );

    expect($recording->resolveComskipIniPath())->toBe(Storage::disk('dvr-test')->path($customIniPath));
});

it('falls back to default ini when custom ini does not exist on disk', function () {
    $recording = makeComskipRecording(
        settingOverrides: ['comskip_ini_path' => 'nonexistent/custom.ini'],
    );

    expect($recording->resolveComskipIniPath())->toBe(config_path('comskip.default.ini'));
});

// ── runComskip() behaviour via the post-processor ───────────────────────────

it('skips comskip when shouldRunComskip returns false', function () {
    $recording = makeComskipRecording(
        ['file_path' => 'library/2025/Show/Show - S01E01.ts'],
        ['enable_comskip' => false],
    );

    $downloader = Mockery::mock(DvrHlsDownloaderService::class);
    $proxy = Mockery::mock(M3uProxyService::class);

    $processor = new DvrPostProcessorService($downloader, $proxy);

    $ref = new ReflectionMethod($processor, 'runComskip');
    $ref->setAccessible(true);

    Storage::disk('dvr-test')->assertMissing('library/2025/Show/Show - S01E01.edl');

    // Method should return early without error
    $ref->invoke($processor, $recording, Storage::disk('dvr-test')->path('library/2025/Show/Show - S01E01.ts'));

    // No .edl should be created since comskip was skipped
    Storage::disk('dvr-test')->assertMissing('library/2025/Show/Show - S01E01.edl');
});

it('produces a .edl sidecar file next to the media file', function () {
    $mediaPath = 'library/2025/Show/Show - S01E01.ts';
    $mediaFullPath = Storage::disk('dvr-test')->path($mediaPath);

    // Create the media file so the fake comskip can "process" it
    Storage::disk('dvr-test')->put($mediaPath, 'fake-ts-content');
    $outputDir = dirname($mediaFullPath);
    if (! is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $recording = makeComskipRecording(
        ['file_path' => $mediaPath],
        ['enable_comskip' => true],
    );

    $downloader = Mockery::mock(DvrHlsDownloaderService::class);
    $proxy = Mockery::mock(M3uProxyService::class);

    $processor = new DvrPostProcessorService($downloader, $proxy);

    $ref = new ReflectionMethod($processor, 'runComskip');
    $ref->setAccessible(true);
    $ref->invoke($processor, $recording, $mediaFullPath);

    Storage::disk('dvr-test')->assertExists('library/2025/Show/Show - S01E01.edl');
});

it('completes without error when comskip binary is missing', function () {
    config()->set('dvr.comskip_path', '/nonexistent/comskip-binary');

    $mediaPath = 'library/2025/Show/Show - S01E01.ts';
    $mediaFullPath = Storage::disk('dvr-test')->path($mediaPath);
    Storage::disk('dvr-test')->put($mediaPath, 'fake-ts-content');
    $outputDir = dirname($mediaFullPath);
    if (! is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $recording = makeComskipRecording(
        ['file_path' => $mediaPath],
        ['enable_comskip' => true],
    );

    $downloader = Mockery::mock(DvrHlsDownloaderService::class);
    $proxy = Mockery::mock(M3uProxyService::class);

    $processor = new DvrPostProcessorService($downloader, $proxy);

    $ref = new ReflectionMethod($processor, 'runComskip');
    $ref->setAccessible(true);

    // Should not throw
    $ref->invoke($processor, $recording, $mediaFullPath);

    // .edl file should not exist since binary failed
    Storage::disk('dvr-test')->assertMissing('library/2025/Show/Show - S01E01.edl');
});

it('completes without error when comskip exits non-zero', function () {
    $failingBinary = Storage::disk('dvr-test')->path('failing-comskip.sh');
    file_put_contents($failingBinary, "#!/bin/sh\nexit 1");
    chmod($failingBinary, 0755);

    config()->set('dvr.comskip_path', $failingBinary);

    $mediaPath = 'library/2025/Show/Show - S01E01.ts';
    $mediaFullPath = Storage::disk('dvr-test')->path($mediaPath);
    Storage::disk('dvr-test')->put($mediaPath, 'fake-ts-content');
    $outputDir = dirname($mediaFullPath);
    if (! is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $recording = makeComskipRecording(
        ['file_path' => $mediaPath],
        ['enable_comskip' => true],
    );

    $downloader = Mockery::mock(DvrHlsDownloaderService::class);
    $proxy = Mockery::mock(M3uProxyService::class);

    $processor = new DvrPostProcessorService($downloader, $proxy);

    $ref = new ReflectionMethod($processor, 'runComskip');
    $ref->setAccessible(true);

    // Should not throw
    $ref->invoke($processor, $recording, $mediaFullPath);

    // .edl file should not exist since comskip exited non-zero
    Storage::disk('dvr-test')->assertMissing('library/2025/Show/Show - S01E01.edl');
});

// ── runComskipOnRecording() via the public reprocess method ──────────────────

it('produces a .edl sidecar via runComskipOnRecording for a completed recording', function () {
    $mediaPath = 'library/2025/Show/Show - S01E01.ts';
    $mediaFullPath = Storage::disk('dvr-test')->path($mediaPath);
    Storage::disk('dvr-test')->put($mediaPath, 'fake-ts-content');
    $outputDir = dirname($mediaFullPath);
    if (! is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $recording = makeComskipRecording(
        ['file_path' => $mediaPath],
        ['enable_comskip' => true],
    );

    $downloader = Mockery::mock(DvrHlsDownloaderService::class);
    $proxy = Mockery::mock(M3uProxyService::class);
    $processor = new DvrPostProcessorService($downloader, $proxy);

    $processor->runComskipOnRecording($recording);

    Storage::disk('dvr-test')->assertExists('library/2025/Show/Show - S01E01.edl');
});

it('runComskipOnRecording skips silently when recording has no file_path', function () {
    $recording = makeComskipRecording(
        ['file_path' => null],
        ['enable_comskip' => true],
    );

    // Make the recording Completed so hasFilePath() is true, but file_path is null
    $recording->update(['status' => DvrRecordingStatus::Completed]);

    $downloader = Mockery::mock(DvrHlsDownloaderService::class);
    $proxy = Mockery::mock(M3uProxyService::class);
    $processor = new DvrPostProcessorService($downloader, $proxy);

    // Should not throw despite no file
    $processor->runComskipOnRecording($recording);
});

it('ProcessComskipOnRecording job produces .edl and clears post_processing_step', function () {
    Notification::fake();

    $mediaPath = 'library/2025/Show/Show - S01E01.ts';
    $mediaFullPath = Storage::disk('dvr-test')->path($mediaPath);
    Storage::disk('dvr-test')->put($mediaPath, 'fake-ts-content');
    $outputDir = dirname($mediaFullPath);
    if (! is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $recording = makeComskipRecording(
        ['file_path' => $mediaPath],
        ['enable_comskip' => true],
    );

    $job = new ProcessComskipOnRecording($recording->id);
    $job->handle(app(DvrPostProcessorService::class));

    $recording->refresh();

    Storage::disk('dvr-test')->assertExists('library/2025/Show/Show - S01E01.edl');
    expect($recording->post_processing_step)->toBeNull();
});

it('sets post_processing_step to commercial detection when comskip runs', function () {
    $mediaPath = 'library/2025/Show/Show - S01E01.ts';
    $mediaFullPath = Storage::disk('dvr-test')->path($mediaPath);
    Storage::disk('dvr-test')->put($mediaPath, 'fake-ts-content');
    $outputDir = dirname($mediaFullPath);
    if (! is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $recording = makeComskipRecording(
        ['file_path' => $mediaPath],
        ['enable_comskip' => true],
    );

    $downloader = Mockery::mock(DvrHlsDownloaderService::class);
    $proxy = Mockery::mock(M3uProxyService::class);

    $processor = new DvrPostProcessorService($downloader, $proxy);

    $ref = new ReflectionMethod($processor, 'runComskip');
    $ref->setAccessible(true);
    $ref->invoke($processor, $recording, $mediaFullPath);

    $recording->refresh();
    expect($recording->post_processing_step)->toBe('Running commercial detection');
});
