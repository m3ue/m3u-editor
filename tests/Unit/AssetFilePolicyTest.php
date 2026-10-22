<?php

use App\Rules\AllowedAssetFile;
use App\Services\AssetFilePolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

it('allows only matching media MIME and extension pairs', function (string $name, string $mime, bool $allowed) {
    expect(AssetFilePolicy::allows($name, $mime))->toBe($allowed);
})->with([
    ['logo.png', 'image/png', true],
    ['LOGO.JPG', 'image/jpeg', true],
    ['sound.wav', 'audio/x-wav', true],
    ['movie.mp4', 'video/mp4', true],
    ['logo.svg', 'image/svg+xml', false],
    ['notes.html', 'text/html', false],
    ['logo.txt', 'image/png', false],
    ['logo.png', 'text/plain', false],
    ['logo', 'image/png', false],
]);

it('requires actual image content and its matching original extension', function () {
    $png = file_get_contents(public_path('placeholder.png'));
    foreach ([['logo.png', $png, true], ['logo.txt', $png, false], ['logo.png', 'ordinary text', false]] as [$name, $bytes, $allowed]) {
        $file = UploadedFile::fake()->createWithContent($name, $bytes);
        expect(Validator::make(['file' => $file], ['file' => [new AllowedAssetFile(imagesOnly: true)]])->passes())->toBe($allowed);
    }
});

it('keeps non-image media outside the raster-only policy', function () {
    expect(AssetFilePolicy::allows('clip.mp4', 'video/mp4', imagesOnly: true))->toBeFalse()
        ->and(AssetFilePolicy::allows('logo.webp', 'image/webp', imagesOnly: true))->toBeTrue();
});
