<?php

use App\Filament\Clusters\Settings\Pages\ManageAssetSettings;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Channels\Pages\EditChannel;
use App\Models\Asset;
use App\Models\Channel;
use App\Models\User;
use App\Services\AssetFilePolicy;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->user = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->user);
});

function assetUploadFixture(string $name, string $kind = 'png'): UploadedFile
{
    $bytes = $kind === 'png' ? file_get_contents(public_path('placeholder.png'))
        : file_get_contents(base_path('tests/Fixtures/assets/sample.'.$kind));

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

it('uploads actual raster audio and video through the library action with generated names', function (string $name, string $kind) {
    Livewire::test(ListAssets::class)
        ->callAction('uploadAsset', ['file' => [assetUploadFixture($name, $kind)]])
        ->assertHasNoFormErrors();

    $asset = Asset::query()->sole();
    expect($asset->path)->toStartWith('assets/library/')
        ->and($asset->name)->not->toBe($name)
        ->and(Storage::disk('public')->exists($asset->path))->toBeTrue()
        ->and(Storage::disk('public')->url($asset->path))->toContain('/storage/assets/library/');
    $response = $this->get($asset->preview_url)->assertOk();
    expect($response->headers->get('Content-Disposition'))->toStartWith($kind === 'png' ? 'inline;' : 'attachment;')
        ->and($response->headers->get('Cache-Control'))->toContain('private');
})->with([['friendly.png', 'png'], ['friendly.wav', 'wav'], ['friendly.mp4', 'mp4']]);

it('rejects unsupported types mismatched extensions and non-image content before storage', function (string $name, string $content) {
    $bytes = $content === 'png' ? file_get_contents(public_path('placeholder.png')) : $content;
    Livewire::test(ListAssets::class)
        ->callAction('uploadAsset', ['file' => [UploadedFile::fake()->createWithContent($name, $bytes)]])
        ->assertHasFormErrors();
    expect(Asset::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('assets/library'))->toBe([]);
})->with([
    ['logo.txt', 'png'],
    ['logo.png', 'ordinary text'],
    ['notes.html', '<!doctype html><title>Notes</title><p>Read me</p>'],
    ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
]);

it('rejects a new file larger than the asset upload limit', function () {
    $file = assetUploadFixture('large.png')->size(AssetFilePolicy::MAX_UPLOAD_KILOBYTES + 1);
    Livewire::test(ListAssets::class)->callAction('uploadAsset', ['file' => [$file]])->assertHasFormErrors();
    expect(Asset::count())->toBe(0);
});

it('uploads a channel logo through the actual nested asset-picker action', function () {
    $channel = Channel::factory()->create(['user_id' => $this->user->id, 'is_custom' => true]);
    $component = Livewire::test(EditChannel::class, ['record' => $channel->id])
        ->callAction(TestAction::make('uploadLogo')->schemaComponent('logo_internal'), ['logo_file' => [assetUploadFixture('channel.png')]])
        ->assertHasNoFormErrors();

    $asset = Asset::query()->sole();
    expect($component->get('data.logo_internal'))->toBe(Storage::disk('public')->url($asset->path));
});

it('rejects non-raster media in the channel logo picker', function () {
    $channel = Channel::factory()->create(['user_id' => $this->user->id, 'is_custom' => true]);
    Livewire::test(EditChannel::class, ['record' => $channel->id])
        ->callAction(TestAction::make('uploadLogo')->schemaComponent('logo_internal'), ['logo_file' => [assetUploadFixture('audio.wav', 'wav')]])
        ->assertHasFormErrors();
    expect(Asset::count())->toBe(0);
});

it('applies the same raster policy to every placeholder uploader and saves valid images', function (string $field) {
    Livewire::test(ManageAssetSettings::class)
        ->assertFormFieldExists($field, fn ($component): bool => $component->getMaxSize() === AssetFilePolicy::MAX_UPLOAD_KILOBYTES
            && $component->shouldPreventFilePathTampering()
            && ! in_array('image/svg+xml', $component->getAcceptedFileTypes(), true))
        ->fillForm([$field => [assetUploadFixture('placeholder.png')]])
        ->call('save')
        ->assertHasNoFormErrors();

    $path = app(GeneralSettings::class)->refresh()->{$field};
    expect($path)->toStartWith('assets/placeholders/')->and(Storage::disk('public')->exists($path))->toBeTrue();

    // Existing settings can still be saved without re-uploading their image.
    Livewire::test(ManageAssetSettings::class)->call('save')->assertHasNoFormErrors();
    expect(app(GeneralSettings::class)->refresh()->{$field})->toBe($path);
})->with(['logo_placeholder_url', 'episode_placeholder_url', 'vod_series_poster_placeholder_url']);
