<?php

use App\Filament\Pages\ReleaseLogs;
use App\Filament\Resources\ChannelScrubbers\ChannelScrubberResource;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('includes use_scrubber and view_release_logs in available permissions', function () {
    $permissions = User::getAvailablePermissions();

    expect($permissions)->toHaveKey('use_scrubber')
        ->and($permissions)->toHaveKey('view_release_logs');
});

it('denies scrubber access without permission', function () {
    expect($this->user->canUseScrubber())->toBeFalse();
    expect(ChannelScrubberResource::canAccess())->toBeFalse();
});

it('grants scrubber access with permission', function () {
    $this->user->permissions = ['use_scrubber'];
    $this->user->save();

    expect($this->user->fresh()->canUseScrubber())->toBeTrue();
    expect(ChannelScrubberResource::canAccess())->toBeTrue();
});

it('denies release logs access without permission', function () {
    expect($this->user->canViewReleaseLogs())->toBeFalse();
    expect(ReleaseLogs::canAccess())->toBeFalse();
});

it('grants release logs access with permission', function () {
    $this->user->permissions = ['view_release_logs'];
    $this->user->save();

    expect($this->user->fresh()->canViewReleaseLogs())->toBeTrue();
    expect(ReleaseLogs::canAccess())->toBeTrue();
});

it('grants all permissions to admin users', function () {
    $admin = User::factory()->make(['is_admin' => true]);

    expect($admin->canUseScrubber())->toBeTrue()
        ->and($admin->canViewReleaseLogs())->toBeTrue();
});
