<?php

declare(strict_types=1);

use App\Enums\DvrRuleType;
use App\Filament\GuestPanel\Widgets\GuestScheduledSeriesWidget;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Set up request attributes and session so HasGuestDvr resolves the correct context.
 */
function setGuestScheduledSeriesContext(Playlist $playlist, PlaylistAuth $auth): void
{
    request()->attributes->set('playlist_uuid', $playlist->uuid);

    $prefix = base64_encode($playlist->uuid).'_';
    session()->put("{$prefix}guest_auth_username", $auth->username);
    session()->put("{$prefix}guest_auth_password", $auth->password);
}

beforeEach(function () {
    config()->set('dvr.dvr_enabled', true);
    config()->set('proxy.proxy_integration_enabled', true);
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->dvrSetting = DvrSetting::factory()->enabled()->for($this->playlist)->for($this->user)->create();
    $this->guestA = PlaylistAuth::factory()
        ->for($this->user)
        ->create(['enabled' => true, 'dvr_enabled' => true]);
    $this->guestB = PlaylistAuth::factory()
        ->for($this->user)
        ->create(['enabled' => true, 'dvr_enabled' => true]);
    setGuestScheduledSeriesContext($this->playlist, $this->guestA);
});

it('excludes series rules owned by a different guest', function () {
    $ownRule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Series,
            'series_title' => 'Own Show',
            'enabled' => true,
            'playlist_auth_id' => $this->guestA->id,
        ]);

    DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Series,
            'series_title' => 'Other Guest Show',
            'enabled' => true,
            'playlist_auth_id' => $this->guestB->id,
        ]);

    $widget = new GuestScheduledSeriesWidget;
    $ids = $widget->getSeriesRules()->pluck('id')->all();

    expect($ids)->toBe([$ownRule->id]);
});

it('excludes owner-created series rules (null playlist_auth_id)', function () {
    $ownRule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Series,
            'series_title' => 'Own Show',
            'enabled' => true,
            'playlist_auth_id' => $this->guestA->id,
        ]);

    DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Series,
            'series_title' => 'Owner Show',
            'enabled' => true,
            'playlist_auth_id' => null,
        ]);

    $widget = new GuestScheduledSeriesWidget;
    $ids = $widget->getSeriesRules()->pluck('id')->all();

    expect($ids)->toBe([$ownRule->id]);
});
