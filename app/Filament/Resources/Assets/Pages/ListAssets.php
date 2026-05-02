<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Assets\Widgets\AssetStatsOverview;
use App\Models\Asset;
use App\Services\AssetInventoryService;
use App\Services\LogoRepositoryService;
use App\Settings\GeneralSettings;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListAssets extends ListRecords
{
    protected static string $resource = AssetResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Manage cached logos and uploaded media assets. Placeholder images can be updated in Settings > Assets.');
    }

    public function mount(): void
    {
        parent::mount();

        app(AssetInventoryService::class)->sync();
    }

    protected function getHeaderActions(): array
    {
        $isRepositoryEnabled = (bool) (app(GeneralSettings::class)->logo_repository_enabled ?? true);

        return [
            Actions\Action::make('uploadAsset')
                ->label(__('Upload Asset'))
                ->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    FileUpload::make('file')
                        ->label(__('Asset file'))
                        ->required()
                        ->disk('public')
                        ->directory('assets/library')
                        ->preserveFilenames(),
                ])
                ->action(function (array $data): void {
                    $asset = app(AssetInventoryService::class)->indexFile('public', $data['file'], 'upload');

                    Notification::make()
                        ->title(__('Asset uploaded'))
                        ->body("Stored {$asset->name}.")
                        ->success()
                        ->send();
                }),
            Actions\ActionGroup::make([
                Actions\Action::make('rescanAssets')
                    ->label(__('Rescan storage'))
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (): void {
                        $count = app(AssetInventoryService::class)->sync();

                        Notification::make()
                            ->title(__('Asset scan complete'))
                            ->body("Indexed {$count} files.")
                            ->success()
                            ->send();
                    }),
                Actions\Action::make('refreshLogoRepositoryCache')
                    ->label(__('Refresh Logo Repository'))
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->visible($isRepositoryEnabled)
                    ->action(function (): void {
                        app(LogoRepositoryService::class)->clearCache();
                        $count = count(app(LogoRepositoryService::class)->getIndex());

                        Notification::make()
                            ->title(__('Logo repository refreshed'))
                            ->body("Indexed {$count} repository entries.")
                            ->success()
                            ->send();
                    }),
                Actions\Action::make('openLogoRepository')
                    ->label(__('View Logo Repository'))
                    ->icon('heroicon-o-eye')
                    ->visible($isRepositoryEnabled)
                    ->url(route('logo.repository'), shouldOpenInNewTab: true),
                Actions\Action::make('clearLogoCache')
                    ->label(__('Clear all cached logos'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $service = app(AssetInventoryService::class);

                        Asset::query()
                            ->where('source', 'logo_cache')
                            ->get()
                            ->each(fn (Asset $asset) => $service->deleteAsset($asset));

                        Notification::make()
                            ->title(__('Cached logos removed'))
                            ->success()
                            ->send();
                    }),
            ])->button()->color('gray')->label(__('Actions')),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AssetStatsOverview::class,
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery();
    }
}
