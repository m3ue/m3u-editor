<?php

namespace App\Filament\Resources\Assets;

use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Assets\Widgets\AssetStatsOverview;
use App\Models\Asset;
use App\Models\User;
use App\Services\AssetInventoryService;
use App\Services\DateFormatService;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class AssetResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;

    protected static ?string $model = Asset::class;

    public static function getNavigationGroup(): ?string
    {
        return __('Tools');
    }

    public static function getModelLabel(): string
    {
        return __('Asset');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Assets');
    }

    public static function getNavigationLabel(): string
    {
        return __('Assets');
    }

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user instanceof User && ($user->isAdmin() || $user->canUseTools()));
    }

    public static function table(Table $table): Table
    {
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('last_modified_at', 'desc')
            ->columns([
                ImageColumn::make('preview')
                    ->label(__('Preview'))
                    ->getStateUsing(fn (Asset $record): ?string => $record->is_image ? $record->preview_url : null)
                    ->square()
                    ->extraImgAttributes(fn ($record): array => ['style' => 'height:2.5rem; width:auto; border-radius:4px;'])
                    ->defaultImageUrl(url('/placeholder.png')),
                IconColumn::make('is_image')
                    ->label(__('Img'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('source')
                    ->badge()
                    ->sortable(),
                TextColumn::make('disk')
                    ->badge()
                    ->sortable(),
                TextColumn::make('path')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('mime_type')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('extension')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('size_bytes')
                    ->label(__('Size'))
                    ->sortable()
                    ->formatStateUsing(fn (?int $state): string => $state ? number_format($state / 1024, 2).' KB' : '—'),
                TextColumn::make('last_modified_at')
                    ->label(__('Modified'))
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->options([
                        'logo_cache' => 'Logo Cache',
                        'upload' => 'Uploads',
                        'placeholder' => 'Placeholders',
                    ]),
                SelectFilter::make('disk')
                    ->options([
                        'local' => 'local',
                        'public' => 'public',
                    ]),
                TernaryFilter::make('is_image')
                    ->label(__('Images only')),
            ])
            ->recordActions([
                Actions\Action::make('preview')
                    ->label(__('Preview'))
                    ->icon('heroicon-o-eye')
                    ->slideOver()
                    ->modalHeading(fn (Asset $record): string => $record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalContent(function (Asset $record) {
                        return view('filament.assets.preview', [
                            'asset' => $record,
                            'metadata' => app(AssetInventoryService::class)->getMetadataForAsset($record),
                        ]);
                    })
                    ->action(fn () => null)
                    ->button()
                    ->hiddenLabel()
                    ->size('sm'),
                Actions\Action::make('delete')
                    ->label(__('Delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Asset $record): void {
                        app(AssetInventoryService::class)->deleteAsset($record);

                        Notification::make()
                            ->title(__('Asset deleted'))
                            ->success()
                            ->send();
                    })
                    ->button()
                    ->hiddenLabel()
                    ->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('deleteSelectedFiles')
                        ->label(__('Delete selected files'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $service = app(AssetInventoryService::class);

                            $records->each(fn (Asset $asset) => $service->deleteAsset($asset));

                            Notification::make()
                                ->title(__('Selected assets deleted'))
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getWidgets(): array
    {
        return [
            AssetStatsOverview::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssets::route('/'),
        ];
    }
}
