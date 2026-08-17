<?php

/**
 * Coverage for commercial-skip (comskip/EDL) on DVR recordings once they have
 * been integrated into the VOD/Series library.
 *
 * Before this, `edl_url` was emitted only by `get_dvr_recordings`, so playing a
 * recording from the Recordings screen got comskip while playing the exact same
 * file from Movies or Series silently did not — the client had no way to reach
 * the EDL. `DvrVodIntegrationService` writes real Channel / Series+Season+Episode
 * rows carrying `dvr_recording_id`, but none of the VOD/series payloads
 * serialized it.
 *
 * Locks in:
 *   1. `get_vod_info` emits `edl_url`/`dvr_uuid` for a DVR-backed movie.
 *   2. `get_series_info` emits them per-episode for a DVR-backed episode.
 *   3. Ordinary VOD/series payloads are unchanged — no keys at all.
 *   4. The keys are withheld when comskip did not run for the recording,
 *      including the per-rule override case (rule off, setting on), which the
 *      old DvrSetting-only gate got wrong in both directions.
 *   5. A credential without DVR access is never handed an EDL URL.
 */

use App\Enums\DvrRecordingStatus;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->group = Group::factory()->for($this->user)->create();

    $this->playlistAuth = PlaylistAuth::create([
        'name' => 'EDL Test Auth',
        'username' => 'edl-user',
        'password' => 'edl-pass',
        'enabled' => true,
        'dvr_enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($this->playlistAuth);

    $this->setting = DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create(['enable_comskip' => true]);

    $this->recording = DvrRecording::factory()
        ->completed()
        ->for($this->user)
        ->for($this->setting)
        // Guest-scheduled recordings carry the credential that made them; the
        // EDL route scopes on it for guest credentials.
        ->create(['playlist_auth_id' => $this->playlistAuth->id]);
});

function dvrEdlApiUrl(string $action, array $params = [], string $username = 'edl-user', string $password = 'edl-pass'): string
{
    return route('xtream.api.player').'?'.http_build_query(array_merge([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ], $params));
}

function dvrEdlVodChannel($test, ?int $dvrRecordingId): Channel
{
    $isDvr = $dvrRecordingId !== null;

    return Channel::factory()
        ->for($test->playlist)
        ->for($test->group)
        ->create([
            'enabled' => true,
            'is_vod' => true,
            'name' => 'Recorded Movie',
            'title' => 'Recorded Movie',
            // Mirror what DvrVodIntegrationService::integrateAsMovie actually
            // writes: is_custom with no last_metadata_fetch. Pinning the fetch
            // timestamp instead would skip the metadata path the real rows take.
            'is_custom' => $isDvr,
            'last_metadata_fetch' => $isDvr ? null : now(),
            'dvr_recording_id' => $dvrRecordingId,
        ]);
}

function dvrEdlEpisode($test, ?int $dvrRecordingId, string $importBatchNo = 'dvr'): Episode
{
    $series = Series::factory()
        ->for($test->user)
        ->for($test->playlist)
        ->create(['enabled' => true, 'import_batch_no' => $importBatchNo]);

    $season = Season::factory()
        ->for($test->user)
        ->for($test->playlist)
        ->for($series)
        ->create(['season_number' => 1]);

    return Episode::factory()
        ->for($test->user)
        ->for($test->playlist)
        ->for($series)
        ->for($season)
        ->create([
            'season' => 1,
            'episode_num' => 1,
            'title' => 'Recorded Episode',
            'import_batch_no' => $importBatchNo,
            'dvr_recording_id' => $dvrRecordingId,
        ]);
}

it('emits edl_url and dvr_uuid on get_vod_info for a DVR-backed movie', function () {
    $channel = dvrEdlVodChannel($this, $this->recording->id);

    $response = $this->getJson(dvrEdlApiUrl('get_vod_info', ['vod_id' => $channel->id]))->assertOk();

    $expected = config('app.url')."/dvr/edl-user/edl-pass/{$this->recording->uuid}/edl";

    expect($response->json('info.edl_url'))->toBe($expected)
        ->and($response->json('info.dvr_uuid'))->toBe($this->recording->uuid)
        // Merged to root as well, matching how the rest of this payload is shaped.
        ->and($response->json('edl_url'))->toBe($expected);
});

it('emits an edl_url that actually resolves to the EDL endpoint', function () {
    // The other tests compare against a string built the same way the code
    // builds it, so they would keep passing if the path shape drifted on both
    // sides. This one follows the URL the client would actually be handed.
    $channel = dvrEdlVodChannel($this, $this->recording->id);

    $response = $this->getJson(dvrEdlApiUrl('get_vod_info', ['vod_id' => $channel->id]))->assertOk();

    $disk = $this->recording->resolveStorageDisk();
    Storage::fake($disk);
    $edlPath = preg_replace('/\.[^.]+$/', '.edl', $this->recording->file_path);
    Storage::disk($disk)->put($edlPath, "10.5 40.25 0\n120.0 180.0 1\n");

    $this->getJson($response->json('info.edl_url'))
        ->assertOk()
        // type 1 lines are not commercial cuts and must be filtered out
        ->assertExactJson([['start' => 10.5, 'end' => 40.25]]);
});

it('omits the edl keys on get_vod_info for an ordinary movie', function () {
    $channel = dvrEdlVodChannel($this, null);

    $response = $this->getJson(dvrEdlApiUrl('get_vod_info', ['vod_id' => $channel->id]))->assertOk();

    expect($response->json('info'))->not->toHaveKey('edl_url')
        ->and($response->json('info'))->not->toHaveKey('dvr_uuid');
});

it('emits edl_url and dvr_uuid per episode on get_series_info', function () {
    $episode = dvrEdlEpisode($this, $this->recording->id);

    $response = $this->getJson(dvrEdlApiUrl('get_series_info', ['series_id' => $episode->series_id]))->assertOk();

    $payload = $response->json('episodes.1.0');

    expect($payload['edl_url'])->toBe(config('app.url')."/dvr/edl-user/edl-pass/{$this->recording->uuid}/edl")
        ->and($payload['dvr_uuid'])->toBe($this->recording->uuid);
});

it('emits edl_url for a DVR episode attached to a non-DVR series', function () {
    // findOrCreateSeries matches by tmdb/tvmaze id or name without filtering on
    // import_batch_no, so recording a show that is already in the library hangs
    // the DVR episode off the existing upstream series. Gating on the series'
    // import_batch_no would silently lose comskip for exactly these.
    $episode = dvrEdlEpisode($this, $this->recording->id, importBatchNo: 'upstream');

    $response = $this->getJson(dvrEdlApiUrl('get_series_info', ['series_id' => $episode->series_id]))->assertOk();

    expect($response->json('episodes.1.0.edl_url'))
        ->toBe(config('app.url')."/dvr/edl-user/edl-pass/{$this->recording->uuid}/edl");
});

it('omits the edl keys on get_series_info for an ordinary episode', function () {
    $episode = dvrEdlEpisode($this, null, importBatchNo: 'upstream');

    $response = $this->getJson(dvrEdlApiUrl('get_series_info', ['series_id' => $episode->series_id]))->assertOk();

    expect($response->json('episodes.1.0'))->not->toHaveKey('edl_url')
        ->and($response->json('episodes.1.0'))->not->toHaveKey('dvr_uuid');
});

it('withholds the edl url when comskip is disabled for the recording', function () {
    $this->setting->update(['enable_comskip' => false]);
    $channel = dvrEdlVodChannel($this, $this->recording->id);

    $response = $this->getJson(dvrEdlApiUrl('get_vod_info', ['vod_id' => $channel->id]))->assertOk();

    expect($response->json('info'))->not->toHaveKey('edl_url');
});

it('honours a per-rule comskip override that disagrees with the setting', function () {
    // The old gate read DvrSetting::enable_comskip alone, but
    // DvrRecording::shouldRunComskip() lets a per-rule flag win. Setting on +
    // rule off means comskip never ran, so no EDL file exists — advertising one
    // sends the client after a 404.
    $rule = DvrRecordingRule::factory()
        ->for($this->user)
        ->for($this->setting)
        ->create(['enable_comskip' => false]);

    $this->recording->update(['dvr_recording_rule_id' => $rule->id]);

    $channel = dvrEdlVodChannel($this, $this->recording->id);

    $response = $this->getJson(dvrEdlApiUrl('get_vod_info', ['vod_id' => $channel->id]))->assertOk();

    expect($response->json('info'))->not->toHaveKey('edl_url');

    // ...and the inverse: setting off + rule on means the .edl does exist.
    $this->setting->update(['enable_comskip' => false]);
    $rule->update(['enable_comskip' => true]);

    $response = $this->getJson(dvrEdlApiUrl('get_vod_info', ['vod_id' => $channel->id]))->assertOk();

    expect($response->json('info.edl_url'))
        ->toBe(config('app.url')."/dvr/edl-user/edl-pass/{$this->recording->uuid}/edl");
});

it('withholds the edl url from a credential without DVR access', function () {
    $this->playlistAuth->update(['dvr_enabled' => false]);
    $channel = dvrEdlVodChannel($this, $this->recording->id);

    $response = $this->getJson(dvrEdlApiUrl('get_vod_info', ['vod_id' => $channel->id]))->assertOk();

    expect($response->json('info'))->not->toHaveKey('edl_url');
});

it('withholds the edl url for a recording that has not completed', function () {
    $this->recording->update(['status' => DvrRecordingStatus::Recording]);
    $channel = dvrEdlVodChannel($this, $this->recording->id);

    $response = $this->getJson(dvrEdlApiUrl('get_vod_info', ['vod_id' => $channel->id]))->assertOk();

    expect($response->json('info'))->not->toHaveKey('edl_url');
});
