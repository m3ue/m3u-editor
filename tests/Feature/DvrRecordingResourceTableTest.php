<?php

use App\Enums\DvrRecordingStatus;
use App\Events\PlaylistCreated;
use App\Filament\Resources\DvrRecordings\DvrRecordingResource;
use App\Filament\Resources\DvrRecordings\Pages\ListDvrRecordings;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-01-15 12:00:00');
    Event::fake([PlaylistCreated::class]);
    config()->set('dvr.dvr_enabled', true);
    config()->set('proxy.proxy_integration_enabled', true);

    $this->user = User::factory()->create([
        'permissions' => ['use_dvr'],
    ]);

    $this->actingAs($this->user);

    $playlist = Playlist::factory()->for($this->user)->create();
    $this->dvrSetting = DvrSetting::factory()
        ->for($this->user)
        ->for($playlist)
        ->enabled()
        ->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('sorts active recordings before non-active recordings by default', function () {
    $scheduled = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'scheduled_start' => now()->addMinutes(20),
            'scheduled_end' => now()->addMinutes(50),
            'title' => 'Scheduled Soon',
        ]);

    $completed = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->completed()
        ->create([
            'status' => DvrRecordingStatus::Completed,
            'scheduled_start' => now()->addMinutes(45),
            'scheduled_end' => now()->addMinutes(90),
            'title' => 'Completed Later Start',
        ]);

    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->recording()
        ->create([
            'scheduled_start' => now()->subMinutes(10),
            'scheduled_end' => now()->addMinutes(40),
            'title' => 'Live Recording',
        ]);

    Livewire::test(ListDvrRecordings::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$recording, $scheduled, $completed], inOrder: true);
});

it('filters recordings with errors', function () {
    $withError = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->failed()
        ->create([
            'title' => 'Has Error',
        ]);

    $withoutError = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'error_message' => null,
            'title' => 'No Error',
        ]);

    Livewire::test(ListDvrRecordings::class)
        ->assertOk()
        ->loadTable()
        ->filterTable('has_error')
        ->assertCanSeeTableRecords([$withError])
        ->assertCanNotSeeTableRecords([$withoutError]);
});

it('formats dates relatively and file sizes with adaptive units', function () {
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Completed,
            'title' => 'Formatted Recording',
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addMinutes(30),
            'file_size_bytes' => 1073741824,
        ]);

    Livewire::test(ListDvrRecordings::class)
        ->assertOk()
        ->loadTable()
        ->assertTableColumnFormattedStateSet('scheduled_start', now()->subHour()->diffForHumans(), $recording)
        ->assertTableColumnFormattedStateSet('scheduled_end', now()->addMinutes(30)->diffForHumans(), $recording)
        ->assertTableColumnFormattedStateSet('file_size_bytes', '1.0 GB', $recording);
});

it('admin sees recordings from all users', function () {
    $otherUser = User::factory()->create(['permissions' => ['use_dvr']]);
    $otherPlaylist = Playlist::factory()->for($otherUser)->create();
    $otherDvrSetting = DvrSetting::factory()
        ->for($otherUser)
        ->for($otherPlaylist)
        ->enabled()
        ->create();

    $myRecording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'title' => 'My Recording',
        ]);
    $theirRecording = DvrRecording::factory()
        ->for($otherUser)
        ->for($otherDvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'title' => 'Their Recording',
        ]);

    $admin = User::factory()->admin()->create(['permissions' => ['use_dvr']]);
    $this->actingAs($admin);

    $ids = DvrRecordingResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($myRecording->id)
        ->toContain($theirRecording->id);
});

it('non-admin sees only their own recordings', function () {
    $otherUser = User::factory()->create(['permissions' => ['use_dvr']]);
    $otherPlaylist = Playlist::factory()->for($otherUser)->create();
    $otherDvrSetting = DvrSetting::factory()
        ->for($otherUser)
        ->for($otherPlaylist)
        ->enabled()
        ->create();

    $myRecording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'title' => 'My Recording',
        ]);
    $theirRecording = DvrRecording::factory()
        ->for($otherUser)
        ->for($otherDvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'title' => 'Their Recording',
        ]);

    $ids = DvrRecordingResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($myRecording->id)
        ->not()->toContain($theirRecording->id);
});

it('displays Owner column with user name', function () {
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Completed,
            'title' => 'Owner Column Test',
        ]);

    Livewire::test(ListDvrRecordings::class)
        ->assertOk()
        ->loadTable()
        ->assertTableColumnFormattedStateSet('user.name', $this->user->name, $recording);
});

it('filters recordings by owner', function () {
    $otherUser = User::factory()->create(['permissions' => ['use_dvr']]);
    $otherPlaylist = Playlist::factory()->for($otherUser)->create();
    $otherDvrSetting = DvrSetting::factory()
        ->for($otherUser)
        ->for($otherPlaylist)
        ->enabled()
        ->create();

    $myRecording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'title' => 'My Recording',
        ]);
    $theirRecording = DvrRecording::factory()
        ->for($otherUser)
        ->for($otherDvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'title' => 'Their Recording',
        ]);

    $admin = User::factory()->admin()->create(['permissions' => ['use_dvr']]);
    $this->actingAs($admin);

    Livewire::test(ListDvrRecordings::class)
        ->assertOk()
        ->loadTable()
        ->filterTable('user_id', $this->user->id)
        ->assertCanSeeTableRecords([$myRecording])
        ->assertCanNotSeeTableRecords([$theirRecording]);
});

it('displays guest name when playlist_auth_id is set', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'name' => 'Guest Sarah',
        'dvr_enabled' => true,
    ]);

    $withGuest = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'title' => 'Guest Recording',
            'playlist_auth_id' => $auth->id,
        ]);

    $withoutGuest = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'title' => 'Owner Recording',
            'playlist_auth_id' => null,
        ]);

    Livewire::test(ListDvrRecordings::class)
        ->assertOk()
        ->loadTable()
        ->assertTableColumnFormattedStateSet('playlistAuth.name', 'Guest Sarah', $withGuest)
        ->assertTableColumnStateSet('playlistAuth.name', null, $withoutGuest);
});

it('filters recordings by guest playlist_auth_id', function () {
    $authA = PlaylistAuth::factory()->for($this->user)->create([
        'name' => 'Guest Alice',
        'dvr_enabled' => true,
    ]);
    $authB = PlaylistAuth::factory()->for($this->user)->create([
        'name' => 'Guest Bob',
        'dvr_enabled' => true,
    ]);

    $aliceRecording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'title' => 'Alice Recording',
            'playlist_auth_id' => $authA->id,
        ]);
    $bobRecording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'title' => 'Bob Recording',
            'playlist_auth_id' => $authB->id,
        ]);

    $admin = User::factory()->admin()->create(['permissions' => ['use_dvr']]);
    $this->actingAs($admin);

    Livewire::test(ListDvrRecordings::class)
        ->assertOk()
        ->loadTable()
        ->filterTable('playlist_auth_id', $authA->id)
        ->assertCanSeeTableRecords([$aliceRecording])
        ->assertCanNotSeeTableRecords([$bobRecording]);
});

it('shows the play action for a completed recording with an owner', function () {
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->completed()
        ->create([
            'title' => 'Completed Playable',
        ]);

    Livewire::test(ListDvrRecordings::class)
        ->assertOk()
        ->loadTable()
        ->assertActionVisible(TestAction::make('play')->table($recording));
});

it('shows the play action for a recording-in-progress', function () {
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->recording()
        ->create([
            'title' => 'Live Recording',
        ]);

    Livewire::test(ListDvrRecordings::class)
        ->assertOk()
        ->loadTable()
        ->assertActionVisible(TestAction::make('play')->table($recording));
});

it('hides the play action for a scheduled recording', function () {
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'title' => 'Scheduled Recording',
        ]);

    Livewire::test(ListDvrRecordings::class)
        ->assertOk()
        ->loadTable()
        ->assertActionHidden(TestAction::make('play')->table($recording));
});

it('hides the play action for a failed recording', function () {
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->failed()
        ->create([
            'title' => 'Failed Recording',
        ]);

    Livewire::test(ListDvrRecordings::class)
        ->assertOk()
        ->loadTable()
        ->assertActionHidden(TestAction::make('play')->table($recording));
});

it('hides the play action when the dvr setting has no owner', function () {
    $orphanSetting = DvrSetting::factory()
        ->for($this->user)
        ->create(['playlist_id' => null]);

    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($orphanSetting)
        ->completed()
        ->create([
            'title' => 'Orphaned Completed Recording',
        ]);

    Livewire::test(ListDvrRecordings::class)
        ->assertOk()
        ->loadTable()
        ->assertActionHidden(TestAction::make('play')->table($recording));
});
