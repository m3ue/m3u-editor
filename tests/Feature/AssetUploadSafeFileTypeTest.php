<?php

/**
 * Issue #1658: the Assets > Upload Asset action had no file-type restriction at all, and
 * AssetPickerAction/placeholder uploads relied only on Filament's ->image() (content-sniffed
 * MIME, via finfo), which a "real GIF bytes, .php filename" polyglot passes - the attacker's
 * chosen extension is what actually gets served back from the public disk, since uploaded
 * files are served directly by nginx with Content-Type derived from that extension. Covers
 * both the naive "wrong content type" case and the polyglot case specifically.
 */

use App\Filament\Actions\AssetPickerAction;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// A real, minimal, valid 1x1 transparent GIF - finfo correctly identifies this as image/gif
// regardless of what filename/extension it's uploaded under.
const GIF_POLYGLOT_BYTES = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x01\x44\x00\x3b";

beforeEach(function () {
    Storage::fake('public');
    $this->user = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->user);
});

it('accepts a genuine image upload through the Upload Asset action', function () {
    Livewire::test(ListAssets::class)
        ->callAction('uploadAsset', data: [
            'file' => [UploadedFile::fake()->image('logo.png')],
        ])
        ->assertHasNoActionErrors();
});

it('rejects a non-image extension through the Upload Asset action', function () {
    Livewire::test(ListAssets::class)
        ->callAction('uploadAsset', data: [
            'file' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
        ])
        ->assertHasActionErrors(['file']);
});

it('rejects a real-GIF-content file uploaded with a .php extension through the Upload Asset action', function () {
    Livewire::test(ListAssets::class)
        ->callAction('uploadAsset', data: [
            'file' => [UploadedFile::fake()->createWithContent('evil.php', GIF_POLYGLOT_BYTES)],
        ])
        ->assertHasActionErrors(['file']);
});

it('requires both a content-type check and a filename-extension check on the shared AssetPickerAction upload field', function () {
    $fileUpload = uploadFieldOf(AssetPickerAction::upload('logo'));

    // FileUpload folds its per-file rules (required/mimetypes/mimes/extensions) into one
    // closure rather than exposing them as flat top-level strings - pull them back out of it.
    $perFileRules = perFileRulesOf($fileUpload);

    // mimetypes: checks real content (via finfo); extensions: checks the uploaded filename -
    // the polyglot defense needs both, since content-sniffing alone is what #1658 exploited.
    expect($perFileRules)->toContain('mimetypes:image/jpeg,image/png,image/gif,image/webp,image/bmp,image/x-icon,image/vnd.microsoft.icon')
        ->and($perFileRules)->toContain('mimes:jpg,jpeg,png,gif,webp,bmp,ico')
        ->and($perFileRules)->toContain('extensions:jpg,jpeg,png,gif,webp,bmp,ico');
});

/**
 * Pulls the single FileUpload component out of an Action's raw schema array without needing
 * a bound Livewire/Schema context - AssetPickerAction::upload() builds its schema as a plain
 * array, not a closure, so this is a direct property read.
 */
function uploadFieldOf(Action $action): FileUpload
{
    $ref = new ReflectionProperty($action, 'schema');
    $ref->setAccessible(true);

    return $ref->getValue($action)[0];
}

/**
 * @return list<string>
 */
function perFileRulesOf(FileUpload $fileUpload): array
{
    $closureRule = collect($fileUpload->getValidationRules())->first(fn ($rule) => $rule instanceof Closure);

    $vars = (new ReflectionFunction($closureRule))->getClosureUsedVariables();

    return $vars['fileRules'];
}
