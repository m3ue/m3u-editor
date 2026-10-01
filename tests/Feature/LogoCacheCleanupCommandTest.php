<?php

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

it('skips expired cleanup when permanent cache is enabled', function () {
    /** @var TestCase $this */
    Storage::fake('local');
    Storage::disk('local')->put('cached-logos/logo_test.png', 'content');

    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->logo_cache_permanent = true;
    app()->instance(GeneralSettings::class, $mockSettings);

    $this->artisan('app:logo-cleanup --force')
        ->expectsOutput('Skipping expired logo cache cleanup because permanent cache is enabled.')
        ->assertExitCode(0);

    expect(Storage::disk('local')->exists('cached-logos/logo_test.png'))->toBeTrue();
});

it('still allows full cleanup when all option is passed', function () {
    /** @var TestCase $this */
    Storage::fake('local');
    Storage::disk('local')->put('cached-logos/logo_test.png', 'content');

    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->logo_cache_permanent = true;
    app()->instance(GeneralSettings::class, $mockSettings);

    $this->artisan('app:logo-cleanup --force --all')
        ->assertExitCode(0);

    expect(Storage::disk('local')->exists('cached-logos/logo_test.png'))->toBeFalse();
});

it('deletes cache files older than the expiry and metadata no copy uses any more', function () {
    /** @var TestCase $this */
    Storage::fake('local');
    $disk = Storage::disk('local');
    $age = fn (string $file, int $days) => touch($disk->path($file), now()->subDays($days)->timestamp);

    // Variant-only entry, expired: its copy and shared metadata both go.
    $disk->put('cached-logos/logo_old@w600.jpg', 'variant');
    $disk->put('cached-logos/logo_old.meta.json', '{}');
    $age('cached-logos/logo_old@w600.jpg', 40);

    // Expired original next to a fresh variant: the metadata stays for the variant.
    $disk->put('cached-logos/logo_mixed.jpg', 'original');
    $disk->put('cached-logos/logo_mixed@w600.jpg', 'variant');
    $disk->put('cached-logos/logo_mixed.meta.json', '{}');
    $age('cached-logos/logo_mixed.jpg', 40);

    // Fresh entry: untouched.
    $disk->put('cached-logos/logo_fresh.jpg', 'original');
    $disk->put('cached-logos/logo_fresh.meta.json', '{}');
    $age('cached-logos/logo_fresh.jpg', 5);

    $this->artisan('app:logo-cleanup --force')->assertExitCode(0);

    expect($disk->files('cached-logos'))->toEqualCanonicalizing([
        'cached-logos/logo_mixed@w600.jpg',
        'cached-logos/logo_mixed.meta.json',
        'cached-logos/logo_fresh.jpg',
        'cached-logos/logo_fresh.meta.json',
    ]);
});
