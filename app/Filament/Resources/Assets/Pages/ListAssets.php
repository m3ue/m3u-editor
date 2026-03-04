<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use App\Services\AssetInventoryService;
use App\Services\LogoRepositoryService;
use App\Settings\GeneralSettings;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAssets extends ListRecords
{
    protected static string $resource = AssetResource::class;

    protected ?string $subheading = 'Manage cached logos and uploaded media assets. Placeholder images can be updated in Settings > Assets.';

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
                ->label('Upload Asset')
                ->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    FileUpload::make('file')
                        ->label('Asset file')
                        ->required()
                        ->disk('public')
                        ->directory('assets/library')
                        ->preserveFilenames(),
                ])
                ->action(function (array $data): void {
                    $asset = app(AssetInventoryService::class)->indexFile('public', $data['file'], 'upload');

                    Notification::make()
                        ->title('Asset uploaded')
                        ->body("Stored {$asset->name}.")
                        ->success()
                        ->send();
                }),
            Actions\ActionGroup::make([
                Actions\Action::make('rescanAssets')
                    ->label('Rescan storage')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (): void {
                        $count = app(AssetInventoryService::class)->sync();

                        Notification::make()
                            ->title('Asset scan complete')
                            ->body("Indexed {$count} files.")
                            ->success()
                            ->send();
                    }),
                Actions\Action::make('refreshLogoRepositoryCache')
                    ->label('Refresh Logo Repository')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->visible($isRepositoryEnabled)
                    ->action(function (): void {
                        app(LogoRepositoryService::class)->clearCache();
                        $count = count(app(LogoRepositoryService::class)->getIndex());

                        Notification::make()
                            ->title('Logo repository refreshed')
                            ->body("Indexed {$count} repository entries.")
                            ->success()
                            ->send();
                    }),
                Actions\Action::make('openLogoRepository')
                    ->label('View Logo Repository')
                    ->icon('heroicon-o-eye')
                    ->visible($isRepositoryEnabled)
                    ->url(route('logo.repository'), shouldOpenInNewTab: true),
                Actions\Action::make('clearLogoCache')
                    ->label('Clear all cached logos')
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
                            ->title('Cached logos removed')
                            ->success()
                            ->send();
                    }),
            ])->button()->color('gray')->label('Actions'),
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
