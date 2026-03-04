<?php

use App\Models\Asset;
use App\Services\AssetInventoryService;
use Illuminate\Support\Facades\Storage;

it('indexes existing cached and uploaded files from storage', function () {
    Storage::fake('local');
    Storage::fake('public');

    Storage::disk('local')->put('cached-logos/existing-logo.png', 'logo');
    Storage::disk('local')->put('cached-logos/existing-logo.meta.json', json_encode(['file' => 'cached-logos/existing-logo.png']));
    Storage::disk('public')->put('assets/library/custom-upload.jpg', 'upload');
    Storage::disk('public')->put('placeholder.png', 'placeholder');

    $indexed = app(AssetInventoryService::class)->sync();

    expect($indexed)->toBe(3);

    expect(Asset::query()->where('disk', 'local')->where('path', 'cached-logos/existing-logo.png')->exists())->toBeTrue();
    expect(Asset::query()->where('disk', 'local')->where('path', 'cached-logos/existing-logo.meta.json')->exists())->toBeFalse();
    expect(Asset::query()->where('disk', 'public')->where('path', 'assets/library/custom-upload.jpg')->exists())->toBeTrue();
    expect(Asset::query()->where('disk', 'public')->where('path', 'placeholder.png')->where('source', 'placeholder')->exists())->toBeTrue();
});

it('prunes records for files removed from storage', function () {
    Storage::fake('local');

    Storage::disk('local')->put('cached-logos/prune-me.png', 'logo');
    app(AssetInventoryService::class)->sync();

    Storage::disk('local')->delete('cached-logos/prune-me.png');
    app(AssetInventoryService::class)->sync();

    expect(Asset::query()->where('disk', 'local')->where('path', 'cached-logos/prune-me.png')->exists())->toBeFalse();
});

it('deletes logo cache metadata when deleting a logo image asset', function () {
    Storage::fake('local');

    Storage::disk('local')->put('cached-logos/logo_abc123.png', 'logo');
    Storage::disk('local')->put('cached-logos/logo_abc123.meta.json', json_encode(['file' => 'cached-logos/logo_abc123.png']));

    app(AssetInventoryService::class)->sync();

    $asset = Asset::query()
        ->where('disk', 'local')
        ->where('path', 'cached-logos/logo_abc123.png')
        ->firstOrFail();

    app(AssetInventoryService::class)->deleteAsset($asset);

    expect(Storage::disk('local')->exists('cached-logos/logo_abc123.png'))->toBeFalse();
    expect(Storage::disk('local')->exists('cached-logos/logo_abc123.meta.json'))->toBeFalse();
});

it('returns logo cache metadata for an asset when companion file exists', function () {
    Storage::fake('local');

    Storage::disk('local')->put('cached-logos/logo_meta_test.png', 'logo');
    Storage::disk('local')->put('cached-logos/logo_meta_test.meta.json', json_encode([
        'file' => 'cached-logos/logo_meta_test.png',
        'content_type' => 'image/png',
    ]));

    app(AssetInventoryService::class)->sync();

    $asset = Asset::query()
        ->where('disk', 'local')
        ->where('path', 'cached-logos/logo_meta_test.png')
        ->firstOrFail();

    $metadata = app(AssetInventoryService::class)->getMetadataForAsset($asset);

    expect($metadata)->toBeArray();
    expect($metadata)->toHaveKey('file', 'cached-logos/logo_meta_test.png');
    expect($metadata)->toHaveKey('content_type', 'image/png');
});
