<?php

/**
 * `get_dvr_recordings` pages with limit/offset (M3U TV loads the whole library
 * this way, m3u-tv #133). Pages must not repeat or skip recordings that share
 * a scheduled start time.
 */

use App\Enums\DvrRecordingStatus;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->auth = PlaylistAuth::create([
        'name' => 'Paging credential',
        'username' => 'paging-user',
        'password' => 'paging-pass',
        'enabled' => true,
        'dvr_enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach([$this->auth->id]);

    $group = Group::factory()->for($this->user)->create();
    $this->channel = Channel::factory()
        ->for($this->playlist)
        ->for($group)
        ->create(['enabled' => true]);

    $this->setting = DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create();
});

function dvrRecordingsPageUrl(array $params = []): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => 'paging-user',
        'password' => 'paging-pass',
        'action' => 'get_dvr_recordings',
        ...$params,
    ]);
}

it('returns every recording exactly once across limit/offset pages when start times tie', function () {
    $start = now()->addDay()->startOfHour();

    $created = DvrRecording::factory()
        ->count(7)
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->for($this->auth, 'playlistAuth')
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'scheduled_start' => $start,
            'scheduled_end' => $start->copy()->addHour(),
        ]);

    $seen = collect();
    foreach ([0, 3, 6] as $offset) {
        $page = $this->postJson(dvrRecordingsPageUrl(['limit' => 3, 'offset' => $offset]))
            ->assertOk()
            ->json();
        $seen = $seen->merge(collect($page)->pluck('uuid'));
    }

    expect($seen)->toHaveCount(7)
        ->and($seen->unique())->toHaveCount(7)
        ->and($seen->sort()->values()->all())->toBe($created->pluck('uuid')->sort()->values()->all());
});

it('returns an empty page past the end of the library', function () {
    DvrRecording::factory()
        ->count(2)
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->for($this->auth, 'playlistAuth')
        ->create(['status' => DvrRecordingStatus::Completed]);

    $this->postJson(dvrRecordingsPageUrl(['limit' => 200, 'offset' => 2]))
        ->assertOk()
        ->assertExactJson([]);
});
