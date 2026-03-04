<?php

namespace App\Filament\Resources\Assets;

use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Models\Asset;
use App\Models\User;
use App\Services\AssetInventoryService;
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

class AssetResource extends Resource
{
    protected static ?string $model = Asset::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Tools';

    protected static ?string $navigationLabel = 'Assets';

    protected static ?int $navigationSort = 5;

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
                return $action->button()->label('Filters');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('last_modified_at', 'desc')
            ->columns([
                ImageColumn::make('preview')
                    ->label('Preview')
                    ->getStateUsing(fn (Asset $record): ?string => $record->is_image ? $record->preview_url : null)
                    ->square()
                    ->size(40)
                    ->defaultImageUrl(url('/placeholder.png')),
                IconColumn::make('is_image')
                    ->label('Img')
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
                    ->label('Size')
                    ->sortable()
                    ->formatStateUsing(fn (?int $state): string => $state ? number_format($state / 1024, 2).' KB' : '—'),
                TextColumn::make('last_modified_at')
                    ->label('Modified')
                    ->dateTime()
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
                    ->label('Images only'),
            ])
            ->recordActions([
                Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->slideOver()
                    ->modalHeading(fn (Asset $record): string => $record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
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
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Asset $record): void {
                        app(AssetInventoryService::class)->deleteAsset($record);

                        Notification::make()
                            ->title('Asset deleted')
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
                        ->label('Delete selected files')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $service = app(AssetInventoryService::class);

                            $records->each(fn (Asset $asset) => $service->deleteAsset($asset));

                            Notification::make()
                                ->title('Selected assets deleted')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssets::route('/'),
        ];
    }
}
