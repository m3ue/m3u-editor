<?php

/**
 * Dispatcharr-style DVR API (`/recordings/`, `/series-rules/`).
 *
 * Covers Sanctum token auth (Bearer, X-API-Key, ApiKey scheme and `?token=`),
 * per-user scoping, token ability checks, and the Dispatcharr request/response
 * shapes translated onto native DvrRecording / DvrRecordingRule rows.
 */

use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Enums\DvrSeriesMode;
use App\Jobs\EnrichDvrMetadata;
use App\Jobs\IntegrateDvrRecordingToVod;
use App\Jobs\ProcessComskipOnRecording;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->epgChannel = EpgChannel::factory()->for($this->user)->create(['channel_id' => 'news.us']);
    $this->channel = Channel::factory()
        ->for($this->user)
        ->for($this->playlist)
        ->for(Group::factory()->for($this->user)->create())
        ->create([
            'enabled' => true,
            'title_custom' => 'News 24',
            'channel' => 5,
            'epg_channel_id' => $this->epgChannel->id,
        ]);

    $this->dvrSetting = DvrSetting::factory()->enabled()->for($this->user)->for($this->playlist)->create();
});

function dvrApiToken(array $abilities = ['view', 'create', 'update', 'delete']): string
{
    return test()->user->createToken('dvr', $abilities)->plainTextToken;
}

function dvrApiHeaders(): array
{
    return ['X-API-Key' => dvrApiToken()];
}

function makeDvrRecording(array $attributes = [], ?string $state = null): DvrRecording
{
    $factory = DvrRecording::factory();
    if ($state) {
        $factory = $factory->{$state}();
    }

    return $factory->create([
        'user_id' => test()->user->id,
        'dvr_setting_id' => test()->dvrSetting->id,
        'channel_id' => test()->channel->id,
        ...$attributes,
    ]);
}

// ──────────────────────────────────────────────────────────────────────────────
// Authentication
// ──────────────────────────────────────────────────────────────────────────────

it('rejects requests without credentials', function () {
    $this->getJson('/recordings/')
        ->assertStatus(401)
        ->assertJsonPath('detail', 'Authentication credentials were not provided.');
});

it('rejects a mock Dispatcharr JWT', function () {
    $playlistAuth = PlaylistAuth::create([
        'name' => 'Guest',
        'username' => 'dvr-guest',
        'password' => 'dvr-guest-pass',
        'enabled' => true,
        'dvr_enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($playlistAuth);

    $jwt = $this->postJson('/api/accounts/token/', [
        'username' => 'dvr-guest',
        'password' => 'dvr-guest-pass',
    ])->assertOk()->json('access');

    $this->getJson('/recordings/', ['Authorization' => "Bearer {$jwt}"])->assertStatus(401);
});

it('forbids a token whose owner has no enabled DVR', function () {
    $this->dvrSetting->update(['enabled' => false]);

    $this->getJson('/recordings/', dvrApiHeaders())->assertStatus(403);
});

it('accepts a Sanctum token as Bearer, X-API-Key, ApiKey scheme, and query token', function () {
    makeDvrRecording();
    $token = dvrApiToken(['view']);

    $this->getJson('/recordings/', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonCount(1);
    $this->getJson('/recordings/', ['X-API-Key' => $token])
        ->assertOk()->assertJsonCount(1);
    $this->getJson('/recordings/', ['Authorization' => "ApiKey {$token}"])
        ->assertOk()->assertJsonCount(1);
    $this->getJson('/recordings/?token='.urlencode($token))
        ->assertOk()->assertJsonCount(1);
});

it('enforces Sanctum token abilities', function () {
    $token = dvrApiToken(['view']);

    $this->postJson('/recordings/', [
        'channel' => $this->channel->id,
        'start_time' => now()->addHour()->toIso8601String(),
        'end_time' => now()->addHours(2)->toIso8601String(),
    ], ['X-API-Key' => $token])->assertStatus(403);
});

it('rejects an expired Sanctum token', function () {
    $token = $this->user->createToken('dvr', ['view'], now()->subMinute())->plainTextToken;

    $this->getJson('/recordings/', ['X-API-Key' => $token])->assertStatus(401);
});

// ──────────────────────────────────────────────────────────────────────────────
// Recordings
// ──────────────────────────────────────────────────────────────────────────────

it('lists recordings in the Dispatcharr shape, hiding cancelled ones', function () {
    $mine = makeDvrRecording(['title' => 'Evening News'], 'completed');
    makeDvrRecording(['status' => DvrRecordingStatus::Cancelled]);

    $response = $this->getJson('/recordings/', dvrApiHeaders())->assertOk();

    $response->assertJsonCount(1)
        ->assertJsonPath('0.id', $mine->id)
        ->assertJsonPath('0.channel', $this->channel->id)
        ->assertJsonPath('0.custom_properties.status', 'completed')
        ->assertJsonPath('0.custom_properties.program.title', 'Evening News')
        ->assertJsonPath('0.custom_properties.program.tvg_id', 'news.us')
        ->assertJsonPath('0.custom_properties.file_url', "/recordings/{$mine->id}/file/");
});

it('does not expose another user\'s recordings to a Sanctum token', function () {
    makeDvrRecording();
    $otherUser = User::factory()->create();
    DvrSetting::factory()->enabled()->for($otherUser)
        ->for(Playlist::factory()->for($otherUser)->create())
        ->create();
    $token = $otherUser->createToken('dvr', ['view'])->plainTextToken;

    $this->getJson('/recordings/', ['X-API-Key' => $token])->assertOk()->assertJsonCount(0);
});

it('routes poster_url through the logo proxy when the DVR playlist has it enabled', function (bool $logoProxyEnabled) {
    $this->playlist->update(['enable_logo_proxy' => $logoProxyEnabled]);
    $rawPoster = 'https://image.tmdb.org/t/p/w500/poster.jpg';
    makeDvrRecording(['metadata' => ['tmdb' => ['poster_url' => $rawPoster]]], 'completed');

    $posterUrl = $this->getJson('/recordings/', dvrApiHeaders())->assertOk()->json('0.custom_properties.poster_url');

    if ($logoProxyEnabled) {
        expect($posterUrl)->not->toBe($rawPoster)->toStartWith(url('/'));
    } else {
        expect($posterUrl)->toBe($rawPoster);
    }
})->with(['logo proxy on' => true, 'logo proxy off' => false]);

it('schedules a recording from a Dispatcharr create payload', function () {
    $start = now()->addHour()->startOfMinute();
    $end = $start->copy()->addMinutes(30);

    $response = $this->postJson('/recordings/', [
        'channel' => (string) $this->channel->id,
        'start_time' => $start->toIso8601String(),
        'end_time' => $end->toIso8601String(),
        'custom_properties' => ['program' => ['title' => 'Evening News']],
    ], dvrApiHeaders());

    $response->assertCreated()
        ->assertJsonPath('channel', $this->channel->id)
        ->assertJsonPath('custom_properties.status', 'scheduled')
        ->assertJsonPath('custom_properties.program.title', 'Evening News');

    $rule = DvrRecordingRule::sole();
    expect($rule->type)->toBe(DvrRuleType::Manual)
        ->and($rule->user_id)->toBe($this->user->id)
        ->and(DvrRecording::find($response->json('id'))->dvr_recording_rule_id)->toBe($rule->id);
});

it('returns 409 when the same window is scheduled twice', function () {
    $payload = [
        'channel' => $this->channel->id,
        'start_time' => now()->addHour()->toIso8601String(),
        'end_time' => now()->addHours(2)->toIso8601String(),
    ];
    $headers = dvrApiHeaders();

    $this->postJson('/recordings/', $payload, $headers)->assertCreated();
    $this->postJson('/recordings/', $payload, $headers)->assertStatus(409);
});

it('returns DRF-style validation errors on create', function () {
    $this->postJson('/recordings/', ['channel' => 999999], dvrApiHeaders())
        ->assertStatus(400)
        ->assertJsonStructure(['start_time', 'end_time']);

    $this->postJson('/recordings/', [
        'channel' => 999999,
        'start_time' => now()->addHour()->toIso8601String(),
        'end_time' => now()->addHours(2)->toIso8601String(),
    ], dvrApiHeaders())->assertStatus(400)->assertJsonStructure(['channel']);
});

it('stops a scheduled recording and reports 409 on a finished one', function () {
    $scheduled = makeDvrRecording();
    $completed = makeDvrRecording([], 'completed');
    $headers = dvrApiHeaders();

    $this->postJson("/recordings/{$scheduled->id}/stop/", [], $headers)
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'stopped']);
    expect($scheduled->fresh()->status)->toBe(DvrRecordingStatus::Cancelled);

    $this->postJson("/recordings/{$completed->id}/stop/", [], $headers)->assertStatus(409);
});

it('deletes a recording', function () {
    $recording = makeDvrRecording();

    $this->deleteJson("/recordings/{$recording->id}/", [], dvrApiHeaders())->assertNoContent();

    expect(DvrRecording::find($recording->id))->toBeNull();
});

it('streams a completed recording file with a query-string token', function () {
    Storage::fake('dvr');
    Storage::disk('dvr')->put('recordings/show/episode.ts', str_repeat('x', 1024));
    $recording = makeDvrRecording(['file_path' => 'recordings/show/episode.ts'], 'completed');
    $token = urlencode(dvrApiToken(['view']));

    $response = $this->get("/recordings/{$recording->id}/file/?token={$token}");

    $response->assertOk()->assertHeader('Accept-Ranges', 'bytes');
    expect($response->streamedContent())->toHaveLength(1024);
});

it('404s when a token touches another user\'s recording', function () {
    $recording = makeDvrRecording();
    $otherUser = User::factory()->create();
    DvrSetting::factory()->enabled()->for($otherUser)
        ->for(Playlist::factory()->for($otherUser)->create())
        ->create();
    $headers = ['X-API-Key' => $otherUser->createToken('dvr', ['view', 'delete'])->plainTextToken];

    $this->getJson("/recordings/{$recording->id}/", $headers)->assertNotFound();
    $this->deleteJson("/recordings/{$recording->id}/", [], $headers)->assertNotFound();
    expect(DvrRecording::find($recording->id))->not->toBeNull();
});

it('bulk-cancels upcoming scheduled recordings', function () {
    makeDvrRecording(['scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(2)]);
    makeDvrRecording(['scheduled_start' => now()->addDay(), 'scheduled_end' => now()->addDay()->addHour()]);
    $completed = makeDvrRecording([], 'completed');

    $this->postJson('/recordings/bulk-delete-upcoming/', [], dvrApiHeaders())
        ->assertOk()
        ->assertJson(['success' => true, 'removed' => 2]);

    expect($completed->fresh()->status)->toBe(DvrRecordingStatus::Completed);
});

it('extends a scheduled recording but not one already in progress', function () {
    $scheduled = makeDvrRecording();
    $originalEnd = $scheduled->scheduled_end->copy();
    $inProgress = makeDvrRecording([], 'recording');
    $headers = dvrApiHeaders();

    $this->postJson("/recordings/{$scheduled->id}/extend/", ['extra_minutes' => 30], $headers)
        ->assertOk()
        ->assertJsonPath('success', true);
    expect($scheduled->fresh()->scheduled_end->equalTo($originalEnd->addMinutes(30)))->toBeTrue();

    $this->postJson("/recordings/{$inProgress->id}/extend/", ['extra_minutes' => 30], $headers)->assertStatus(409);
    $this->postJson("/recordings/{$scheduled->id}/extend/", ['extra_minutes' => 0], $headers)->assertStatus(400);
});

it('updates recording metadata and re-integrates a completed recording', function () {
    $recording = makeDvrRecording(['title' => 'Old Title', 'file_path' => 'recordings/show/episode.ts'], 'completed');
    $headers = dvrApiHeaders();

    $this->postJson("/recordings/{$recording->id}/update-metadata/", ['title' => 'New Title', 'description' => ''], $headers)
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($recording->fresh()->title)->toBe('New Title');
    Queue::assertPushed(IntegrateDvrRecordingToVod::class, fn ($job) => $job->recordingId === $recording->id);

    $this->postJson("/recordings/{$recording->id}/update-metadata/", ['title' => ' '], $headers)->assertStatus(400);
});

it('queues artwork refresh only for completed recordings with enrichment enabled', function () {
    $this->dvrSetting->update(['enable_metadata_enrichment' => true]);
    $completed = makeDvrRecording([], 'completed');
    $scheduled = makeDvrRecording();
    $headers = dvrApiHeaders();

    $this->postJson("/recordings/{$completed->id}/refresh-artwork/", [], $headers)
        ->assertOk()
        ->assertJsonPath('success', true);
    Queue::assertPushed(EnrichDvrMetadata::class, fn ($job) => $job->recordingId === $completed->id);

    $this->postJson("/recordings/{$scheduled->id}/refresh-artwork/", [], $headers)->assertStatus(400);
});

it('queues comskip for a recording with a file', function () {
    $completed = makeDvrRecording([], 'completed');
    $scheduled = makeDvrRecording();
    $headers = dvrApiHeaders();

    $this->postJson("/recordings/{$completed->id}/comskip/", [], $headers)
        ->assertOk()
        ->assertJson(['success' => true, 'queued' => true]);
    Queue::assertPushed(ProcessComskipOnRecording::class, fn ($job) => $job->recordingId === $completed->id);

    $this->postJson("/recordings/{$scheduled->id}/comskip/", [], $headers)->assertStatus(400);
});

it('registers every DVR endpoint at the root alongside the other API routes, not under /api', function () {
    $dvrUris = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'dispatcharr.dvr.'))
        ->map(fn ($route) => $route->uri());

    expect($dvrUris)->not->toBeEmpty()
        ->and($dvrUris->every(fn (string $uri) => str_starts_with($uri, 'recordings') || str_starts_with($uri, 'series-rules')))->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// Series rules
// ──────────────────────────────────────────────────────────────────────────────

it('creates a series rule from tvg_id + title and upserts on repeat', function () {
    $headers = dvrApiHeaders();

    $this->postJson('/series-rules/', [
        'tvg_id' => 'news.us',
        'title' => 'Evening News',
        'mode' => 'all',
    ], $headers)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('rules.0.title', 'Evening News')
        ->assertJsonPath('rules.0.tvg_id', 'news.us')
        ->assertJsonPath('rules.0.mode', 'all');

    $this->postJson('/series-rules/', [
        'tvg_id' => 'news.us',
        'title' => 'Evening News',
        'mode' => 'new',
    ], $headers)->assertOk()->assertJsonCount(1, 'rules')->assertJsonPath('rules.0.mode', 'new');

    $rule = DvrRecordingRule::sole();
    expect($rule->type)->toBe(DvrRuleType::Series)
        ->and($rule->channel_id)->toBe($this->channel->id)
        ->and($rule->series_mode)->toBe(DvrSeriesMode::NewFlag);
});

it('rejects series rule options the native DVR cannot honour', function () {
    $headers = dvrApiHeaders();

    $this->postJson('/series-rules/', ['title' => 'News', 'title_mode' => 'regex'], $headers)
        ->assertStatus(400);
    $this->postJson('/series-rules/', ['description' => 'election'], $headers)
        ->assertStatus(400);
    $this->postJson('/series-rules/', ['title' => 'News', 'tvg_id' => 'unmapped.tvg'], $headers)
        ->assertStatus(400);
});

it('deletes a series rule and cancels only its upcoming scheduled recordings', function () {
    $rule = DvrRecordingRule::factory()->series()->create([
        'user_id' => $this->user->id,
        'dvr_setting_id' => $this->dvrSetting->id,
        'series_title' => 'Evening News',
        'enabled' => false,
    ]);
    $upcoming = makeDvrRecording(['dvr_recording_rule_id' => $rule->id]);
    $kept = makeDvrRecording(['dvr_recording_rule_id' => $rule->id], 'completed');

    $this->deleteJson('/series-rules/?'.http_build_query(['tvg_id' => '', 'title' => 'Evening News']), [], dvrApiHeaders())
        ->assertOk()
        ->assertJson(['success' => true, 'rules' => [], 'removed' => 1]);

    expect(DvrRecordingRule::find($rule->id))->toBeNull()
        ->and($upcoming->fresh()->status)->toBe(DvrRecordingStatus::Cancelled)
        ->and($kept->fresh()->status)->toBe(DvrRecordingStatus::Completed);
});

it('previews series rule matches without saving a rule', function () {
    $programme = EpgProgramme::factory()->upcoming(60)->create([
        'title' => 'Evening News',
        'epg_channel_id' => 'news.us',
    ]);
    EpgProgramme::factory()->upcoming(90)->create([
        'title' => 'Other Show',
        'epg_channel_id' => 'news.us',
    ]);

    $this->postJson('/series-rules/preview/', [
        'tvg_id' => 'news.us',
        'title' => 'Evening News',
        'mode' => 'all',
    ], dvrApiHeaders())
        ->assertOk()
        ->assertJson(['total' => 1, 'limit' => 25, 'epg_found' => true, 'warn' => false])
        ->assertJsonPath('matches.0.id', $programme->id)
        ->assertJsonPath('matches.0.tvg_id', 'news.us')
        ->assertJsonPath('matches.0.will_record', true);

    expect(DvrRecordingRule::count())->toBe(0);
});

it('evaluates series rules and schedules newly matching airings', function () {
    $rule = DvrRecordingRule::factory()->series()->create([
        'user_id' => $this->user->id,
        'dvr_setting_id' => $this->dvrSetting->id,
        'channel_id' => $this->channel->id,
        'series_title' => 'Evening News',
    ]);

    // The airing appears in the EPG after the rule was created.
    EpgProgramme::factory()->upcoming(60)->create([
        'title' => 'Evening News',
        'epg_channel_id' => 'news.us',
    ]);

    $this->postJson('/series-rules/evaluate/', ['tvg_id' => 'news.us'], dvrApiHeaders())
        ->assertOk()
        ->assertJson(['success' => true, 'scheduled' => 1])
        ->assertJsonPath('details.0.title', 'Evening News')
        ->assertJsonPath('details.0.tvg_id', 'news.us')
        ->assertJsonPath('details.0.created', 1);

    expect($rule->recordings()->count())->toBe(1);
});

it('bulk-removes upcoming recordings for a series title', function () {
    makeDvrRecording(['title' => 'Evening News', 'normalized_title' => 'evening news']);
    makeDvrRecording(['title' => 'Other Show', 'normalized_title' => 'other show']);

    $this->postJson('/series-rules/bulk-remove/', [
        'tvg_id' => 'news.us',
        'title' => 'Evening News',
        'scope' => 'title',
    ], dvrApiHeaders())->assertOk()->assertJson(['success' => true, 'removed' => 1]);
});
