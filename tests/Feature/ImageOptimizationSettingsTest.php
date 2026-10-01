<?php

use App\Enums\ImageProfile;
use App\Filament\Clusters\Settings\Pages\ManageAssetSettings;
use App\Models\User;
use App\Settings\GeneralSettings;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'proxy.image_resize_enabled' => null,
        'proxy.image_resize_poster_width' => null,
        'proxy.image_resize_backdrop_width' => null,
        'proxy.image_resize_title_logo_width' => null,
        'proxy.image_resize_photo_width' => null,
        'proxy.image_resize_quality' => null,
        'proxy.image_resize_max' => 1920,
    ]);
});

it('uses the profile defaults out of the box', function () {
    expect(ImageProfile::Poster->maxWidth())->toBe(600)
        ->and(ImageProfile::Backdrop->maxWidth())->toBe(1280)
        ->and(ImageProfile::TitleLogo->maxWidth())->toBe(800)
        ->and(ImageProfile::Photo->maxWidth())->toBe(300)
        ->and(ImageProfile::quality())->toBeNull();
});

it('saves image optimization sizes from the Assets settings page', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageAssetSettings::class)
        ->fillForm([
            'image_optimization_enabled' => true,
            'image_poster_width' => 500,
            'image_backdrop_width' => 1600,
            'image_quality' => 82,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ImageProfile::Poster->maxWidth())->toBe(500)
        ->and(ImageProfile::Backdrop->maxWidth())->toBe(1600)
        ->and(ImageProfile::quality())->toBe(82);
});

it('turns optimization off from the Assets settings page', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageAssetSettings::class)
        ->fillForm(['image_optimization_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ImageProfile::optimizationEnabled())->toBeFalse()
        ->and(ImageProfile::Poster->maxWidth())->toBeNull();
});

it('lets environment variables override the saved settings and locks the fields', function () {
    $settings = app(GeneralSettings::class);
    $settings->image_poster_width = 500;
    $settings->save();

    config(['proxy.image_resize_poster_width' => '450', 'proxy.image_resize_quality' => '90']);

    expect(ImageProfile::Poster->maxWidth())->toBe(450)
        ->and(ImageProfile::quality())->toBe(90);

    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageAssetSettings::class)
        ->assertFormFieldDisabled('image_poster_width')
        ->assertFormFieldDisabled('image_quality')
        ->assertFormFieldEnabled('image_backdrop_width');
});

it('caps every profile width at the configured maximum', function () {
    config(['proxy.image_resize_max' => 1000]);

    $settings = app(GeneralSettings::class);
    $settings->image_backdrop_width = 1600;
    $settings->save();

    expect(ImageProfile::Backdrop->maxWidth())->toBe(1000);
});

it('maps media server image types and image orientation to profiles', function () {
    expect(ImageProfile::fromMediaServerImageType('Primary'))->toBe(ImageProfile::Poster)
        ->and(ImageProfile::fromMediaServerImageType('Backdrop'))->toBe(ImageProfile::Backdrop)
        ->and(ImageProfile::fromMediaServerImageType('Thumb'))->toBe(ImageProfile::Backdrop)
        ->and(ImageProfile::fromMediaServerImageType('Logo'))->toBe(ImageProfile::TitleLogo)
        ->and(ImageProfile::forDimensions(1920, 1080))->toBe(ImageProfile::Backdrop)
        ->and(ImageProfile::forDimensions(1000, 1500))->toBe(ImageProfile::Poster)
        ->and(ImageProfile::forDimensions(800, 800))->toBe(ImageProfile::Poster);
});
