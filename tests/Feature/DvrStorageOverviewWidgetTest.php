<?php

declare(strict_types=1);

use App\Filament\Widgets\DvrStorageOverviewWidget;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('dvr.dvr_enabled', true);
    config()->set('proxy.proxy_integration_enabled', true);
});

it('is visible to admin users only', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    expect(DvrStorageOverviewWidget::canView())->toBeTrue();
});

it('is hidden from non-admin users', function () {
    $user = User::factory()->create(['permissions' => ['use_dvr']]);
    $this->actingAs($user);

    expect(DvrStorageOverviewWidget::canView())->toBeFalse();
});

it('computes per-user storage with quota', function () {
    $user = User::factory()->create(['permissions' => ['use_dvr']]);
    $playlist = Playlist::factory()->for($user)->create();
    $dvrSetting = DvrSetting::factory()
        ->for($user)
        ->for($playlist)
        ->enabled()
        ->create(['global_disk_quota_gb' => 10]);

    DvrRecording::factory()
        ->for($user)
        ->for($dvrSetting)
        ->create(['file_size_bytes' => 1073741824]); // 1 GB

    DvrRecording::factory()
        ->for($user)
        ->for($dvrSetting)
        ->create(['file_size_bytes' => 536870912]); // 0.5 GB

    DvrRecording::factory()
        ->for($user)
        ->for($dvrSetting)
        ->create(['file_size_bytes' => null]);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $widget = app(DvrStorageOverviewWidget::class);
    $data = invade($widget)->getViewData();
    $rows = $data['rows'];

    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect($row['user']->id)->toBe($user->id)
        ->and($row['recording_count'])->toBe(3)
        ->and((int) $row['used_bytes'])->toBe(intval(1073741824 + 536870912))
        ->and((int) $row['quota_bytes'])->toBe(intval(10 * 1024 ** 3))
        ->and($row['percent'])->toBe(15.0);
});

it('returns null percent when quota is zero', function () {
    $user = User::factory()->create(['permissions' => ['use_dvr']]);
    $playlist = Playlist::factory()->for($user)->create();
    $dvrSetting = DvrSetting::factory()
        ->for($user)
        ->for($playlist)
        ->enabled()
        ->create(['global_disk_quota_gb' => 0]);

    DvrRecording::factory()
        ->for($user)
        ->for($dvrSetting)
        ->create(['file_size_bytes' => 1073741824]);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $widget = app(DvrStorageOverviewWidget::class);
    $data = invade($widget)->getViewData();
    $rows = $data['rows'];

    expect($rows)->toHaveCount(1);
    expect($rows->first()['percent'])->toBeNull();
    expect($rows->first()['quota_bytes'])->toBeNull();
});
