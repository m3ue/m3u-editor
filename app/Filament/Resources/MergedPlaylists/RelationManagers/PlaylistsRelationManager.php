<?php

namespace App\Filament\Resources\MergedPlaylists\RelationManagers;

use App\Enums\Status;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlaylistsRelationManager extends RelationManager
{
    protected static string $relationship = 'playlists';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('groups_count')
                    ->label('Groups')
                    ->counts('groups')
                    ->sortable(),
                TextColumn::make('channels_count')
                    ->label('Channels')
                    ->counts('channels')
                    ->sortable(),
                TextColumn::make('enabled_channels_count')
                    ->label('Enabled Channels')
                    ->counts('enabled_channels')
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(fn (Status $state) => $state->getColor()),
                TextColumn::make('synced')
                    ->label('Last Synced')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name'])
                    ->recordSelectOptionsQuery(
                        fn (Builder $query, $livewire) => $query
                            ->select(['id', 'name'])
                            ->where('user_id', $livewire->ownerRecord->user_id)
                            ->orderBy('name')
                    ),

                // Advanced attach when adding pivot values:
                // Tables\Actions\AttachAction::make()->schema(fn(Tables\Actions\AttachAction $action): array => [
                //     $action->getRecordSelect(),
                //     Forms\Components\TextInput::make('title')
                //         ->label('Title')
                //         ->required(),
                // ]),
            ])
            ->recordActions([
                DetachAction::make()
                    ->icon('heroicon-o-x-circle')
                    ->button()
                    ->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()->color('warning'),
                ]),
            ]);
    }
}
