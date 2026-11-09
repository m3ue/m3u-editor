<?php

use App\Livewire\EpgViewer;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * Editing a channel while the JS viewer is showing tomorrow must refresh tomorrow's
 * programmes, not silently replace them with today's. fetchProgrammeData() used to always
 * fetch "today" regardless of which date the viewer had open.
 */
uses(RefreshDatabase::class);

it("refreshes the viewer's currently selected date, not always today", function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create(['is_cached' => true]);
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create(['channel_id' => 'date.channel']);

    $todayStart = now()->startOfDay()->addHours(10);
    $tomorrowStart = now()->addDay()->startOfDay()->addHours(10);

    seedEpgProgrammeCache($epg, [
        ['date.channel', [
            'title' => 'Today Show',
            'start' => $todayStart->format('Y-m-d H:i:s'),
            'stop' => $todayStart->copy()->addHour()->format('Y-m-d H:i:s'),
        ]],
        ['date.channel', [
            'title' => 'Tomorrow Show',
            'start' => $tomorrowStart->format('Y-m-d H:i:s'),
            'stop' => $tomorrowStart->copy()->addHour()->format('Y-m-d H:i:s'),
        ]],
    ]);

    $fetch = new ReflectionMethod(EpgViewer::class, 'fetchProgrammeData');
    $viewer = new EpgViewer;

    // No editingDate set: falls back to today (unchanged default behavior).
    $today = $fetch->invoke($viewer, $epgChannel, 1);
    expect($today[0]['title'])->toBe('Today Show');

    // editingDate set to tomorrow (as the JS viewer would send when editing while on that day).
    $viewer->editingDate = now()->addDay()->format('Y-m-d');
    $tomorrow = $fetch->invoke($viewer, $epgChannel, 1);

    expect($tomorrow[0]['title'])->toBe('Tomorrow Show');
});
