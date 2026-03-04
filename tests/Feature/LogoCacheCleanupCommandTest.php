<?php

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Storage;

it('skips expired cleanup when permanent cache is enabled', function () {
    /** @var \Tests\TestCase $this */
    Storage::fake('local');
    Storage::disk('local')->put('cached-logos/logo_test.png', 'content');

    $mockSettings = \Mockery::mock(GeneralSettings::class);
    $mockSettings->logo_cache_permanent = true;
    app()->instance(GeneralSettings::class, $mockSettings);

    $this->artisan('app:logo-cleanup --force')
        ->expectsOutput('Skipping expired logo cache cleanup because permanent cache is enabled.')
        ->assertExitCode(0);

    expect(Storage::disk('local')->exists('cached-logos/logo_test.png'))->toBeTrue();
});

it('still allows full cleanup when all option is passed', function () {
    /** @var \Tests\TestCase $this */
    Storage::fake('local');
    Storage::disk('local')->put('cached-logos/logo_test.png', 'content');

    $mockSettings = \Mockery::mock(GeneralSettings::class);
    $mockSettings->logo_cache_permanent = true;
    app()->instance(GeneralSettings::class, $mockSettings);

    $this->artisan('app:logo-cleanup --force --all')
        ->assertExitCode(0);

    expect(Storage::disk('local')->exists('cached-logos/logo_test.png'))->toBeFalse();
});
