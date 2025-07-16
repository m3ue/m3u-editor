<?php

namespace App\Filament\Resources\CustomPlaylistResource\RelationManagers;

use App\Filament\Resources\SeriesResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class SeriesRelationManager extends RelationManager
{
    protected static string $relationship = 'series';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return SeriesResource::infolist($infolist);
    }

    public function table(Table $table): Table
    {
        $ownerRecord = $this->ownerRecord;
        return $table->persistFiltersInSession()
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->recordTitleAttribute('name')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns(SeriesResource::getTableColumns(showPlaylist: true))
            ->filters(SeriesResource::getTableFilters(showPlaylist: true))
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->form(fn(Tables\Actions\AttachAction $action): array => [
                        $action
                            ->getRecordSelect()
                            ->getSearchResultsUsing(function (string $search) {
                                $searchLower = strtolower($search);
                                $series = Auth::user()->series()
                                    ->withoutEagerLoads()
                                    ->with('playlist')
                                    ->where(function ($query) use ($searchLower) {
                                        $query->whereRaw('LOWER(series.name) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(series.cast) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(series.plot) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(series.genre) LIKE ?', ["%{$searchLower}%"]);
                                    })
                                    ->limit(50)
                                    ->get();

                                // Create options array
                                $options = [];
                                foreach ($series as $seriesItem) {
                                    $displayTitle = $seriesItem->name;
                                    $playlistName = $seriesItem->playlist->name ?? 'Unknown';
                                    $options[$seriesItem->id] = "{$displayTitle} [{$playlistName}]";
                                }

                                return $options;
                            })
                            ->getOptionLabelFromRecordUsing(function ($record) {
                                $displayTitle = $record->title_custom ?: $record->title;
                                $playlistName = $record->getEffectivePlaylist()->name ?? 'Unknown';
                                $options[$record->id] = "{$displayTitle} [{$playlistName}]";
                                return "{$displayTitle} [{$playlistName}]";
                            })
                    ])

                // Advanced attach when adding pivot values:
                // Tables\Actions\AttachAction::make()->form(fn(Tables\Actions\AttachAction $action): array => [
                //     $action->getRecordSelect(),
                //     Forms\Components\TextInput::make('title')
                //         ->label('Title')
                //         ->required(),
                // ]),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->color('danger')
                    ->button()->hiddenLabel()
                    ->icon('heroicon-o-x-circle')
                    ->size('sm'),
                ...SeriesResource::getTableActions(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make()->color('danger'),
                ...SeriesResource::getTableBulkActions(),
            ]);
    }
}
