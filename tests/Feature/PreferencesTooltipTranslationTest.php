<?php

use App\Filament\Pages\Preferences;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;

it('resolves preference hint icon tooltips through the translation layer', function () {
    $this->actingAs(User::factory()->admin()->create());

    $assertTooltip = function (string $locale, string $expected): void {
        App::setLocale($locale);

        Livewire::test(Preferences::class)
            ->assertFormFieldExists('max_concurrent_floating_players', function (TextInput $field) use ($expected): bool {
                Assert::assertSame(
                    $expected,
                    $field->getHintIconTooltip(),
                );

                return true;
            });
    };

    try {
        $assertTooltip('es', 'Establezca en 0 (o borre el valor) para ilimitado.');
        $assertTooltip('fr', 'Réglez sur 0 (ou effacez la valeur) pour un nombre illimité.');
        $assertTooltip('en', 'Set to 0 (or clear value) for unlimited.');
    } finally {
        App::setLocale(config('app.locale'));
    }
});

it('does not leave literal tooltips in Filament hint icons', function () {
    $filesWithLiteralHintIconTooltips = collect(File::allFiles(app_path('Filament')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->filter(fn ($file): bool => preg_match(
            '/->hintIcon\s*\((?:(?!\n\s*->).)*?tooltip:\s*(?:(?!__\()[\'\"]|fn\s*\([^)]*\)\s*=>\s*(?![^\n]*__\()[^\n]*[\'\"])/s',
            $file->getContents(),
        ) === 1)
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($filesWithLiteralHintIconTooltips)->toBeEmpty();
});
