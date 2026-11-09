<?php

use Illuminate\Support\Facades\File;

/**
 * app:sync-translations used to only scan app/Filament, app/Enums and
 * resources/views/filament, silently leaving Livewire components, jobs,
 * services and their views out of the synced set (see issue #1669 follow-up).
 * These guard against that scope narrowing again.
 */
it('finds __() strings in app/ outside the old Filament-only scan path', function () {
    $fixture = app_path('Livewire/__SyncTranslationsFixture.php');
    File::put($fixture, "<?php\n__('Sync Translations Fixture String One');\n");

    try {
        $this->artisan('app:sync-translations', ['--dry-run' => true])
            ->expectsOutputToContain('Sync Translations Fixture String One')
            ->assertSuccessful();
    } finally {
        File::delete($fixture);
    }
});

it('finds __() strings in resources/views/ outside the old filament-only scan path', function () {
    $fixture = resource_path('views/livewire/__sync-translations-fixture.blade.php');
    File::put($fixture, "{{ __('Sync Translations Fixture String Two') }}\n");

    try {
        $this->artisan('app:sync-translations', ['--dry-run' => true])
            ->expectsOutputToContain('Sync Translations Fixture String Two')
            ->assertSuccessful();
    } finally {
        File::delete($fixture);
    }
});
