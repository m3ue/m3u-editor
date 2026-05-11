<?php

declare(strict_types=1);

use App\Enums\DvrRuleType;
use App\Filament\GuestPanel\Pages\GuestBrowseShows;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\EpgProgramme;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Set up the request attributes and session so HasGuestDvr / HasPlaylist resolve correctly.
 * Direct method calls on the component class work correctly with this setup.
 * Note: Livewire::test() creates a new synthetic request and cannot carry over
 * request attributes, so rendering tests follow the GuestDashboardAuthTest precedent
 * and are skipped.
 */
function setupGuestDvrContext(Playlist $playlist, PlaylistAuth $auth): void
{
    request()->attributes->set('playlist_uuid', $playlist->uuid);

    $prefix = base64_encode($playlist->uuid).'_';
    session()->put("{$prefix}guest_auth_username", $auth->username);
    session()->put("{$prefix}guest_auth_password", $auth->password);
}

/**
 * Instantiate the component for direct method testing (without Livewire rendering).
 */
function makeGuestBrowseShows(): GuestBrowseShows
{
    return app(GuestBrowseShows::class);
}

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->dvrSetting = DvrSetting::factory()->enabled()->for($this->playlist)->for($this->user)->create();
    $this->auth = PlaylistAuth::factory()
        ->for($this->user)
        ->create([
            'enabled' => true,
            'dvr_enabled' => true,
        ]);
    setupGuestDvrContext($this->playlist, $this->auth);
});

// --- canAccess ---

it('denies access when dvr_enabled is false on PlaylistAuth', function () {
    $this->auth->update(['dvr_enabled' => false]);

    expect(GuestBrowseShows::canAccess())->toBeFalse();
});

it('denies access when PlaylistAuth is disabled', function () {
    $this->auth->update(['enabled' => false]);

    expect(GuestBrowseShows::canAccess())->toBeFalse();
});

it('grants access when dvr_enabled is true and DvrSetting exists', function () {
    expect(GuestBrowseShows::canAccess())->toBeTrue();
});

it('denies access when no DvrSetting exists for the playlist', function () {
    $this->dvrSetting->delete();

    expect(GuestBrowseShows::canAccess())->toBeFalse();
});

// --- Rendering (skipped — guest panel synthetic requests cannot carry route/attribute context) ---

it('renders the guest browse shows page', function () {
    $this->markTestSkipped('Filament guest panel Livewire tests cannot carry request attribute context through synthetic requests');
});

// --- Search ---

it('starts with searched false and empty grouped shows', function () {
    $component = makeGuestBrowseShows();

    expect($component->searched)->toBeFalse()
        ->and($component->groupedShows)->toBe([]);
});

it('sets searched to true after calling search', function () {
    $component = makeGuestBrowseShows();
    $component->keyword = 'Anything';
    $component->search();

    expect($component->searched)->toBeTrue();
});

it('returns grouped shows matching the keyword', function () {
    EpgProgramme::factory()->create([
        'title' => 'The Wire',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    $component = makeGuestBrowseShows();
    $component->keyword = 'The Wire';
    $component->search();

    expect($component->groupedShows)->toHaveCount(1)
        ->and($component->groupedShows[0]['title'])->toBe('The Wire');
});

it('returns empty grouped shows when no programmes match', function () {
    $component = makeGuestBrowseShows();
    $component->keyword = 'Nonexistent Show XYZ';
    $component->search();

    expect($component->groupedShows)->toBe([]);
});

it('groups multiple airings of the same title into one card', function () {
    EpgProgramme::factory()->count(3)->create([
        'title' => 'The Wire',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    $component = makeGuestBrowseShows();
    $component->keyword = 'The Wire';
    $component->search();

    expect($component->groupedShows)->toHaveCount(1)
        ->and($component->groupedShows[0]['airing_count'])->toBe(3);
});

// --- recordOnce ---

it('creates a Once rule with playlist_auth_id and dvr_setting_id stamped', function () {
    $programme = EpgProgramme::factory()->create([
        'title' => 'Breaking Bad',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    $component = makeGuestBrowseShows();
    $component->keyword = 'Breaking Bad';
    $component->search();
    $component->recordOnce($programme->id);

    $rule = DvrRecordingRule::where('programme_id', $programme->id)->first();
    expect($rule)->not->toBeNull()
        ->and($rule->type)->toBe(DvrRuleType::Once)
        ->and($rule->dvr_setting_id)->toBe($this->dvrSetting->id)
        ->and($rule->playlist_auth_id)->toBe($this->auth->id)
        ->and($rule->user_id)->toBe($this->user->id);
});

it('does not duplicate a Once rule for the same programme', function () {
    $programme = EpgProgramme::factory()->create([
        'title' => 'Breaking Bad',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    $component = makeGuestBrowseShows();
    $component->keyword = 'Breaking Bad';
    $component->search();
    $component->recordOnce($programme->id);
    $component->recordOnce($programme->id);

    expect(DvrRecordingRule::where('programme_id', $programme->id)->count())->toBe(1);
});

it('warns when recordOnce is called without a DvrSetting', function () {
    $this->dvrSetting->delete();

    $programme = EpgProgramme::factory()->create([
        'title' => 'Breaking Bad',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    $component = makeGuestBrowseShows();
    $component->recordOnce($programme->id);

    expect(DvrRecordingRule::count())->toBe(0);
});

// --- recordSeriesDefaults ---

it('creates a Series rule with playlist_auth_id stamped', function () {
    EpgProgramme::factory()->create([
        'title' => 'Breaking Bad',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    $component = makeGuestBrowseShows();
    $component->keyword = 'Breaking Bad';
    $component->search();
    $component->recordSeriesDefaults('Breaking Bad');

    $rule = DvrRecordingRule::where('series_title', 'Breaking Bad')->first();
    expect($rule)->not->toBeNull()
        ->and($rule->type)->toBe(DvrRuleType::Series)
        ->and($rule->dvr_setting_id)->toBe($this->dvrSetting->id)
        ->and($rule->playlist_auth_id)->toBe($this->auth->id)
        ->and($rule->user_id)->toBe($this->user->id);
});

it('does not duplicate a Series rule for the same title', function () {
    $component = makeGuestBrowseShows();
    $component->recordSeriesDefaults('Breaking Bad');
    $component->recordSeriesDefaults('Breaking Bad');

    expect(DvrRecordingRule::where('series_title', 'Breaking Bad')->count())->toBe(1);
});

// --- recordSeriesWithOptions ---

it('creates a Series rule with custom options and playlist_auth_id', function () {
    $component = makeGuestBrowseShows();
    $component->seriesNewOnly = 1;
    $component->seriesPriority = 75;
    $component->seriesStartEarly = 60;
    $component->seriesEndLate = 120;
    $component->recordSeriesWithOptions('The Wire');

    $rule = DvrRecordingRule::where('series_title', 'The Wire')->first();
    expect($rule)->not->toBeNull()
        ->and($rule->playlist_auth_id)->toBe($this->auth->id)
        ->and($rule->new_only)->toBeTrue()
        ->and($rule->priority)->toBe(75)
        ->and($rule->start_early_seconds)->toBe(60)
        ->and($rule->end_late_seconds)->toBe(120);
});

// --- openShowDetail / closeShowDetail ---

it('sets selectedShowTitle when openShowDetail is called', function () {
    $component = makeGuestBrowseShows();
    $component->openShowDetail('Breaking Bad');

    expect($component->selectedShowTitle)->toBe('Breaking Bad');
});

it('clears selectedShowTitle when closeShowDetail is called', function () {
    $component = makeGuestBrowseShows();
    $component->openShowDetail('Breaking Bad');
    $component->closeShowDetail();

    expect($component->selectedShowTitle)->toBe('');
});
