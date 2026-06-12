<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['permissions' => ['use_dvr']]);
});

it('canUseDvr returns true when both configs are enabled and user has permission', function () {
    config()->set('dvr.dvr_enabled', true);
    config()->set('proxy.proxy_integration_enabled', true);

    expect($this->user->canUseDvr())->toBeTrue();
});

it('canUseDvr returns false when DVR_ENABLED config is false', function () {
    config()->set('dvr.dvr_enabled', false);
    config()->set('proxy.proxy_integration_enabled', true);

    expect($this->user->canUseDvr())->toBeFalse();
});

it('canUseDvr returns false when proxy integration config is false', function () {
    config()->set('dvr.dvr_enabled', true);
    config()->set('proxy.proxy_integration_enabled', false);

    expect($this->user->canUseDvr())->toBeFalse();
});

it('canUseDvr returns false when both configs are disabled', function () {
    config()->set('dvr.dvr_enabled', false);
    config()->set('proxy.proxy_integration_enabled', false);

    expect($this->user->canUseDvr())->toBeFalse();
});

it('canUseDvr returns false when user lacks the use_dvr permission even if configs are enabled', function () {
    config()->set('dvr.dvr_enabled', true);
    config()->set('proxy.proxy_integration_enabled', true);
    $this->user->update(['permissions' => []]);

    expect($this->user->canUseDvr())->toBeFalse();
});
