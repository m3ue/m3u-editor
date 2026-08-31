<?php

/**
 * The provider EPG that ProcessM3uImportComplete auto-creates for an Xtream
 * playlist with import_epg enabled must be de-duplicated by the provider tie
 * (epgs.playlist_id), not by exact URL string match. DNS failover rewrites the
 * EPG host, and the old URL-only guard would then miss the existing EPG and
 * create a second (third, fourth...) one on every subsequent sync.
 */

use App\Enums\EpgSourceType;
use App\Jobs\ProcessM3uImportComplete;
use App\Models\Epg;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SyncPipelineService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['dev.disable_sync_logs' => true]);
    Bus::fake();
    Http::preventStrayRequests();

    $this->partialMock(SyncPipelineService::class, function ($mock) {
        $mock->shouldReceive('startRun')->andReturnNull();
        $mock->shouldReceive('expandPipelineAfterImport')->andReturnNull();
        $mock->shouldReceive('completePhase')->andReturnNull();
    });
});

function runImportComplete(User $user, Playlist $playlist): void
{
    (new ProcessM3uImportComplete(
        userId: $user->id,
        playlistId: $playlist->id,
        batchNo: 'batch-'.uniqid(),
        start: Carbon::now()->subMinute(),
        isNew: false,
        runningLiveImport: true,
        runningVodImport: false,
    ))->handle(app(GeneralSettings::class));
}

function xtreamPlaylist(User $user, string $url): Playlist
{
    return Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
        'xtream' => true,
        'xtream_config' => [
            'url' => $url,
            'username' => 'user',
            'password' => 'pass',
            'import_epg' => true,
        ],
    ]));
}

it('creates the provider EPG with a playlist tie on first import', function () {
    $user = User::factory()->create();
    $playlist = xtreamPlaylist($user, 'http://primary.example.com:8080');

    runImportComplete($user, $playlist);

    $epgs = Epg::where('user_id', $user->id)->get();
    expect($epgs)->toHaveCount(1)
        ->and($epgs->first()->playlist_id)->toBe($playlist->id)
        ->and($epgs->first()->url)->toBe('http://primary.example.com:8080/xmltv.php?username=user&password=pass');
});

it('does not create a second EPG when the tied one has a failed-over host', function () {
    $user = User::factory()->create();
    $playlist = xtreamPlaylist($user, 'http://new-host.example.com:8080');

    // Existing tied EPG whose URL still points at the pre-failover host.
    Epg::factory()->for($user)->create([
        'playlist_id' => $playlist->id,
        'source_type' => 'url',
        'url' => 'http://old-host.example.com:8080/xmltv.php?username=user&password=pass',
    ]);

    runImportComplete($user, $playlist);

    expect(Epg::where('user_id', $user->id)->count())->toBe(1);
});

it('does not recreate an EPG the user deliberately unlinked when the URL still matches', function () {
    $user = User::factory()->create();
    $playlist = xtreamPlaylist($user, 'http://primary.example.com:8080');

    // Untied, but URL matches what would be generated.
    Epg::factory()->for($user)->create([
        'playlist_id' => null,
        'source_type' => 'url',
        'url' => 'http://primary.example.com:8080/xmltv.php?username=user&password=pass',
    ]);

    runImportComplete($user, $playlist);

    expect(Epg::where('user_id', $user->id)->count())->toBe(1)
        ->and(Epg::where('user_id', $user->id)->first()->playlist_id)->toBeNull();
});

it('ignores a SchedulesDirect EPG tied to the playlist and still creates the URL EPG', function () {
    $user = User::factory()->create();
    $playlist = xtreamPlaylist($user, 'http://primary.example.com:8080');

    Epg::factory()->for($user)->create([
        'playlist_id' => $playlist->id,
        'source_type' => 'schedules_direct',
        'url' => null,
    ]);

    runImportComplete($user, $playlist);

    $urlEpgs = Epg::where('user_id', $user->id)->where('source_type', EpgSourceType::URL)->get();
    expect($urlEpgs)->toHaveCount(1)
        ->and($urlEpgs->first()->playlist_id)->toBe($playlist->id);
});
