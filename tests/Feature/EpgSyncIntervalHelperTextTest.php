<?php

use App\Enums\EpgSourceType;
use App\Filament\Resources\Epgs\Pages\EditEpg;
use App\Models\Epg;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    // The Schedules Direct section loads its country list when the form renders
    Http::fake(['json.schedulesdirect.org/20141201/available/countries' => Http::response([])]);
});

$notice = 'Schedules Direct will sync, at most, once per hour, regardless of configured schedule.';

it('explains the hourly limit on the sync schedule of a Schedules Direct EPG', function () use ($notice) {
    $epg = Epg::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'test@example.com',
        'sd_password' => 'password',
        'auto_sync' => true,
        'sync_interval' => '*/15 * * * *',
    ]);

    Livewire::test(EditEpg::class, ['record' => $epg->id])->assertSee($notice);
});

it('does not show the hourly limit for other EPGs', function () use ($notice) {
    $epg = Epg::factory()->create([
        'user_id' => $this->user->id,
        'auto_sync' => true,
        'sync_interval' => '*/15 * * * *',
    ]);

    Livewire::test(EditEpg::class, ['record' => $epg->id])->assertDontSee($notice);
});
