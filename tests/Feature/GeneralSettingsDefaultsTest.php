<?php

use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // GeneralSettings is a scoped singleton that gets resolved mid-migration
    // (e.g. by 2025_10_09_074924_add_additional_stream_file_options), before
    // the settings migrations below it have run, freezing a stale payload.
    // Re-read from the repository so the migrated defaults are asserted.
    app(GeneralSettings::class)->refresh();
});

it('defaults dynamic group cache to disabled', function () {
    expect(app(GeneralSettings::class)->enable_dynamic_group_cache)->toBeFalse();
});

it('defaults cache location to null', function () {
    expect(app(GeneralSettings::class)->dynamic_group_cache_location)->toBeNull();
});

it('defaults lazy loading to disabled', function () {
    expect(app(GeneralSettings::class)->dynamic_group_cache_lazy_load)->toBeFalse();
});

it('defaults cache refresh schedule to 3am daily', function () {
    expect(app(GeneralSettings::class)->dynamic_group_cache_schedule)->toBe('0 3 * * *');
});

it('defaults max concurrent downloads to 2', function () {
    expect(app(GeneralSettings::class)->dynamic_group_cache_max_concurrent_downloads)->toBe(2);
});

it('defaults retry cooldown to 360 minutes', function () {
    expect(app(GeneralSettings::class)->dynamic_group_cache_retry_cooldown_minutes)->toBe(360);
});

it('defaults failure cooldown to 24 hours', function () {
    expect(app(GeneralSettings::class)->dynamic_group_cache_failure_cooldown_hours)->toBe(24);
});
