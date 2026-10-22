<?php

use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

function previewFixture(string $name = 'logo.png', ?string $content = null, string $databaseMime = 'image/png'): Asset
{
    $path = 'assets/library/'.$name;
    Storage::disk('public')->put($path, $content ?? file_get_contents(public_path('placeholder.png')));

    return Asset::factory()->create(['path' => $path, 'name' => $name, 'mime_type' => $databaseMime]);
}

it('requires authentication and preserves the tools permission boundary', function () {
    $asset = previewFixture();
    $this->get(route('assets.preview', $asset))->assertRedirect();
    $this->actingAs(User::factory()->create(['permissions' => []]))->get(route('assets.preview', $asset))->assertForbidden();
});

it('serves genuine raster images inline to administrators and tools users', function (bool $admin) {
    $asset = previewFixture(databaseMime: 'text/plain');
    $user = User::factory()->create(['is_admin' => $admin, 'permissions' => ['use_tools' => true]]);
    $response = $this->actingAs($user)->get(route('assets.preview', $asset))
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($response->headers->get('Cache-Control'))->toContain('private');
})->with([true, false]);

it('downloads legacy documents and SVG instead of rendering them inline', function (string $name, string $content) {
    $asset = previewFixture($name, $content);
    $response = $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('assets.preview', $asset))
        ->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
    expect(Storage::disk('public')->exists($asset->path))->toBeTrue();
})->with([
    ['notes.html', '<!doctype html><title>Legacy notes</title><p>Read me</p>'],
    ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1"/></svg>'],
]);

it('does not trust stale database MIME metadata or a raster-looking extension', function () {
    $asset = previewFixture('logo.png', 'ordinary text', 'image/png');
    $response = $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('assets.preview', $asset))
        ->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($asset->isInlinePreviewable())->toBeFalse();
});

it('returns not found for missing storage files', function () {
    $asset = Asset::factory()->create();
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('assets.preview', $asset))->assertNotFound();
});
