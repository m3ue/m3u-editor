<?php

/**
 * Tests for the DVR EDL endpoint:
 *  GET /dvr/{username}/{password}/{uuid}/edl
 *
 *  — Returns 401 with invalid credentials
 *  — Returns 404 for unknown recording
 *  — Returns [] for recordings without a completed file
 *  — Returns [] when no .edl sidecar exists
 *  — Parses EDL and returns only type-0 (commercial) segments
 *  — Ignores non-commercial EDL types (1 = mute, 2 = scene, 3 = skip)
 */

use App\Enums\DvrRecordingStatus;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('dvr-test');
});

function makeEdlRecording(DvrRecordingStatus $status = DvrRecordingStatus::Completed): array
{
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create([
        'storage_disk' => 'dvr-test',
    ]);
    $recording = DvrRecording::factory()
        ->for($user)
        ->for($setting)
        ->when(
            $status === DvrRecordingStatus::Completed,
            fn ($f) => $f->completed(),
            fn ($f) => $f->state(['status' => $status, 'file_path' => null]),
        )
        ->create();

    return [$user, $playlist, $recording];
}

it('returns 401 for invalid credentials', function () {
    [, , $recording] = makeEdlRecording();

    $this->get("/dvr/bad-user/bad-pass/{$recording->uuid}/edl")
        ->assertStatus(401);
});

it('returns 404 for a recording belonging to another user', function () {
    [$user, $playlist] = makeEdlRecording();
    $other = DvrRecording::factory()->for(User::factory()->create())->create();

    $this->get("/dvr/{$user->name}/{$playlist->uuid}/{$other->uuid}/edl")
        ->assertStatus(404);
});

it('returns empty array when recording has no completed file', function () {
    [$user, $playlist, $recording] = makeEdlRecording(DvrRecordingStatus::Recording);

    $this->get("/dvr/{$user->name}/{$playlist->uuid}/{$recording->uuid}/edl")
        ->assertOk()
        ->assertExactJson([]);
});

it('returns empty array when no edl sidecar exists', function () {
    [$user, $playlist, $recording] = makeEdlRecording();

    $this->get("/dvr/{$user->name}/{$playlist->uuid}/{$recording->uuid}/edl")
        ->assertOk()
        ->assertExactJson([]);
});

it('parses commercial segments from an edl file', function () {
    [$user, $playlist, $recording] = makeEdlRecording();

    $edlPath = pathinfo($recording->file_path, PATHINFO_DIRNAME)
        .'/'.pathinfo($recording->file_path, PATHINFO_FILENAME).'.edl';

    Storage::disk('dvr-test')->put($edlPath, "1.00\t120.00\t0\n300.50\t420.75\t0");

    $this->get("/dvr/{$user->name}/{$playlist->uuid}/{$recording->uuid}/edl")
        ->assertOk()
        ->assertJson([
            ['start' => 1.0, 'end' => 120.0],
            ['start' => 300.5, 'end' => 420.75],
        ])
        ->assertJsonCount(2);
});

it('ignores non-commercial edl types', function () {
    [$user, $playlist, $recording] = makeEdlRecording();

    $edlPath = pathinfo($recording->file_path, PATHINFO_DIRNAME)
        .'/'.pathinfo($recording->file_path, PATHINFO_FILENAME).'.edl';

    // type 0 = commercial (included), 1 = mute, 2 = scene, 3 = skip (all ignored)
    Storage::disk('dvr-test')->put($edlPath, "10.00\t20.00\t1\n50.00\t90.00\t0\n200.00\t210.00\t2\n350.00\t400.00\t3");

    $this->get("/dvr/{$user->name}/{$playlist->uuid}/{$recording->uuid}/edl")
        ->assertOk()
        ->assertJson([['start' => 50.0, 'end' => 90.0]])
        ->assertJsonCount(1);
});
