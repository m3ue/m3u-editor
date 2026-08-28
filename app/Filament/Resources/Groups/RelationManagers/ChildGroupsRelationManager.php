<?php

namespace App\Filament\Resources\Groups\RelationManagers;

use App\Models\Group;
use App\Services\MergedGroupService;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The groups merged into a merged group. Shown on the Group / VOD Group edit page in
 * place of the Channels manager. The header "Manage Groups" action is the same one the
 * list view uses; detaching a row releases it back to its own name.
 */
class ChildGroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    protected static ?string $title = 'Merged Groups';

    protected $listeners = ['refreshRelation' => '$refresh'];

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) $ownerRecord->is_merged;
    }

    public function table(Table $table): Table
    {
        /** @var Group $merged */
        $merged = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('sort_order', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->formatStateUsing(fn (?string $state, Group $record): string => filled($state) ? $state : (string) $record->name_internal)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name_internal')
                    ->label(__('Default name'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('channels_count')
                    ->label(__('Channels'))
                    ->counts('channels')
                    ->badge()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('Sort Order'))
                    ->sortable(),
            ])
            ->headerActions([
                MergedGroupService::manageChildrenAction($merged)
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
