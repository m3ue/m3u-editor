<?php

namespace App\Filament\Resources\Categories\RelationManagers;

use App\Models\Category;
use App\Services\MergedGroupService;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The series categories merged into a merged category. Shown on the Category edit page
 * in place of the Series manager. The header "Manage Categories" action is the same one
 * the list view uses; detaching a row releases it back to its own name.
 */
class ChildCategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    protected static ?string $title = 'Merged Categories';

    protected $listeners = ['refreshRelation' => '$refresh'];

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) $ownerRecord->is_merged;
    }

    public function table(Table $table): Table
    {
        /** @var Category $merged */
        $merged = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('sort_order', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->formatStateUsing(fn (?string $state, Category $record): string => filled($state) ? $state : (string) $record->name_internal)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name_internal')
                    ->label(__('Default name'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('series_count')
                    ->label(__('Series'))
                    ->counts('series')
                    ->badge()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('Sort Order'))
                    ->sortable(),
            ])
            ->headerActions([
                MergedGroupService::manageCategoryChildrenAction($merged)
                    ->after(fn ($livewire) => $livewire->dispatch('refreshRelation')),
            ])
            ->recordActions([
                DissociateAction::make()
                    ->label(__('Detach'))
                    ->button()->size('sm')->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                DissociateBulkAction::make()
                    ->label(__('Detach selected')),
            ]);
    }
}
