<?php

use App\Enums\DvrRecordingStatus;
use App\Events\PlaylistCreated;
use App\Filament\Resources\DvrRecordings\Pages\ListDvrRecordings;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
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
