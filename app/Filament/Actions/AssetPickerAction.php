<?php

namespace App\Filament\Actions;

use App\Models\Asset;
use App\Services\AssetInventoryService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class AssetPickerAction
{
    public static function upload(string $field = 'logo'): Action
    {
        return Action::make('uploadLogo')
            ->label('Upload')
            ->icon('heroicon-o-arrow-up-tray')
            ->schema([
                FileUpload::make('logo_file')
                    ->label('Logo image')
                    ->image()
                    ->required()
                    ->disk('public')
                    ->directory('assets/library')
                    ->visibility('public'),
            ])
            ->action(function (array $data, Set $schemaSet) use ($field): void {
                $path = $data['logo_file'];
                $schemaSet($field, Storage::disk('public')->url($path));
                app(AssetInventoryService::class)->indexFile('public', $path, 'upload');
            });
    }

    public static function browse(string $field = 'logo'): Action
    {
        return Action::make('browseAssets')
            ->label('Browse Library')
            ->icon('heroicon-o-photo')
            ->schema([
                Select::make('asset_url')
                    ->label('Select image')
                    ->searchable()
                    ->live()
                    ->allowHtml()
                    ->placeholder('Search by filename...')
                    ->options(fn (): array => static::assetOptions())
                    ->getSearchResultsUsing(fn (string $search): array => static::assetOptions($search)),
                Placeholder::make('asset_preview')
                    ->label('Preview')
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        $get('asset_url')
                            ? '<img src="'.e($get('asset_url')).'" class="max-h-32 w-auto object-contain rounded border border-gray-200 dark:border-gray-700 p-1 mt-1">'
                            : '<span class="text-sm text-gray-400 dark:text-gray-500 italic">No image selected</span>'
                    ))
                    ->live(),
            ])
            ->modalSubmitActionLabel('Use this image')
            ->action(function (array $data, Set $schemaSet) use ($field): void {
                if (! empty($data['asset_url'])) {
                    $schemaSet($field, $data['asset_url']);
                }
            });
    }

    /**
     * @return array<string, string>
     */
    private static function assetOptions(string $search = ''): array
    {
        return Asset::query()
            ->where('is_image', true)
            ->where('disk', 'public')
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->mapWithKeys(function (Asset $asset): array {
                $url = Storage::disk('public')->url($asset->path);

                return [
                    $url => '<div class="flex items-center gap-2 py-0.5">'
                        .'<img src="'.e($url).'" class="h-8 w-8 object-contain rounded shrink-0" onerror="this.style.display=\'none\'">'
                        .'<span class="truncate">'.e($asset->name).'</span>'
                        .'</div>',
                ];
            })
            ->toArray();
    }
}
