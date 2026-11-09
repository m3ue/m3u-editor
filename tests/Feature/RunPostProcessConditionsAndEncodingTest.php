<?php

/**
 * Covers RunPostProcess defects reported against the webhook/URL path:
 *  - The "Sync time" condition offered by the form persists field key
 *    "time", but getFieldValue() only recognized "synctime", so the
 *    condition always evaluated to null/empty.
 *  - With "Send as JSON body" disabled, variables must be sent as form
 *    data; Laravel's HTTP client defaults ->post() to a JSON body unless
 *    ->asForm() is used, so the non-JSON branch was silently sending JSON.
 *  - The "Sync time" output variable ("time") was empty when the job ran
 *    without the optional sync log, despite the persisted sync_time.
 */

use App\Enums\Status;
use App\Jobs\RunPostProcess;
use App\Models\Playlist;
use App\Models\PlaylistSyncStatus;
use App\Models\PostProcess;
use App\Models\PostProcessLog;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake();
    config(['proxy.allow_private_webhook_urls' => false]);
});

/**
 * @param  array<string, mixed>  $metadata
 */
function runPostProcessWebhookFor(array $metadata, float $syncTime = 12.0, array $conditions = []): PostProcessLog
{
    $playlist = Playlist::factory()->for(User::factory()->create())->createQuietly([
        'name' => 'Synthetic Playlist',
        'status' => Status::Completed,
        'sync_time' => $syncTime,
    ]);
    $postProcess = PostProcess::factory()->create([
        'user_id' => $playlist->user_id,
        'event' => 'synced',
        'enabled' => true,
        'conditions' => $conditions,
        'metadata' => array_merge([
            'local' => 'url',
            'path' => 'http://198.51.100.1/hook',
        ], $metadata),
    ]);

    (new RunPostProcess($postProcess, $playlist))->handle(app(GeneralSettings::class));

    return PostProcessLog::where('post_process_id', $postProcess->id)->sole();
}

it('evaluates the Sync time condition against the persisted sync duration', function () {
    Http::preventStrayRequests();
    Http::fake(['http://198.51.100.1/*' => Http::response('ok', 200)]);

    $log = runPostProcessWebhookFor(
        ['post' => false],
        syncTime: 12.0,
        conditions: [
            ['field' => 'time', 'operator' => 'greater_than', 'value' => '1'],
        ],
    );

    expect($log->status)->toBe('success');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '198.51.100.1'));
});

it('skips when the Sync time condition is not met', function () {
    $log = runPostProcessWebhookFor(
        ['post' => false],
        syncTime: 12.0,
        conditions: [
            ['field' => 'time', 'operator' => 'is_empty'],
        ],
    );

    expect($log->status)->toBe('skipped');
});

it('sends variables as form data when Send as JSON body is disabled', function () {
    Http::preventStrayRequests();
    Http::fake(['http://198.51.100.1/*' => Http::response('ok', 200)]);

    runPostProcessWebhookFor([
        'post' => true,
        'json_body' => false,
        'post_vars' => [
            ['variable_name' => 'playlist_name', 'value' => 'name'],
        ],
    ]);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'http://198.51.100.1/hook'
            && $request->isForm()
            && $request['playlist_name'] === 'Synthetic Playlist';
    });
});

it('still sends a JSON body when Send as JSON body is enabled', function () {
    Http::preventStrayRequests();
    Http::fake(['http://198.51.100.1/*' => Http::response('ok', 200)]);

    runPostProcessWebhookFor([
        'post' => true,
        'json_body' => true,
        'post_vars' => [
            ['variable_name' => 'playlist_name', 'value' => 'name'],
        ],
    ]);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'http://198.51.100.1/hook'
            && $request->isJson()
            && $request['playlist_name'] === 'Synthetic Playlist';
    });
});

it('resolves the Sync time output variable from the persisted sync duration without a sync log', function () {
    Http::preventStrayRequests();
    Http::fake(['http://198.51.100.1/*' => Http::response('ok', 200)]);

    runPostProcessWebhookFor([
        'post' => true,
        'json_body' => true,
        'post_vars' => [
            ['variable_name' => 'duration', 'value' => 'time'],
        ],
    ], syncTime: 12.5);

    Http::assertSent(fn (Request $request): bool => (float) $request['duration'] === 12.5);
});

it('prefers the sync log duration for the Sync time output variable when one is supplied', function () {
    Http::preventStrayRequests();
    Http::fake(['http://198.51.100.1/*' => Http::response('ok', 200)]);

    $playlist = Playlist::factory()->for(User::factory()->create())->createQuietly([
        'status' => Status::Completed,
        'sync_time' => 99.0,
    ]);
    $postProcess = PostProcess::factory()->create([
        'user_id' => $playlist->user_id,
        'event' => 'synced',
        'enabled' => true,
        'metadata' => [
            'local' => 'url',
            'path' => 'http://198.51.100.1/hook',
            'post' => true,
            'json_body' => true,
            'post_vars' => [['variable_name' => 'duration', 'value' => 'time']],
        ],
    ]);
    $syncLog = new PlaylistSyncStatus(['sync_stats' => ['time' => 12.5]]);

    (new RunPostProcess($postProcess, $playlist, $syncLog))->handle(app(GeneralSettings::class));

    Http::assertSent(fn (Request $request): bool => (float) $request['duration'] === 12.5);
});

it('evaluates the Sync time condition against the sync log duration when one is supplied', function () {
    Http::preventStrayRequests();
    Http::fake(['http://198.51.100.1/*' => Http::response('ok', 200)]);

    $playlist = Playlist::factory()->for(User::factory()->create())->createQuietly([
        'status' => Status::Completed,
        'sync_time' => 1.0,
    ]);
    $postProcess = PostProcess::factory()->create([
        'user_id' => $playlist->user_id,
        'event' => 'synced',
        'enabled' => true,
        'conditions' => [
            ['field' => 'time', 'operator' => 'greater_than', 'value' => '10'],
        ],
        'metadata' => [
            'local' => 'url',
            'path' => 'http://198.51.100.1/hook',
            'post' => false,
        ],
    ]);
    $syncLog = new PlaylistSyncStatus(['sync_stats' => ['time' => 12.5]]);

    (new RunPostProcess($postProcess, $playlist, $syncLog))->handle(app(GeneralSettings::class));

    expect(PostProcessLog::where('post_process_id', $postProcess->id)->sole()->status)->toBe('success');
    Http::assertSentCount(1);
});
