<?php

namespace App\Filament\Resources\SeriesResource\RelationManagers;

use App\Infolists\Components\SeriesPreview;
use App\Livewire\ChannelStreamStats;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EpisodesRelationManager extends RelationManager
{
    protected static string $relationship = 'episodes';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['season']);
            })
            ->defaultGroup('season')
            ->defaultSort('episode_num', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('episode_num')
                    ->label('Episode')
                    ->sortable(),
                Tables\Columns\TextColumn::make('season.name')
                    ->label('Season Name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->url(fn($record) => $record->url)
                    ->openUrlInNewTab()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->slideOver()
                    ->hiddenLabel()
                    ->button()->size('sm'),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                // @TODO - add download? Would need to generate streamlink files and compress then download...
            ]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                SeriesPreview::make('preview')
                    ->columnSpanFull()
                    ->hiddenLabel(),
                Infolists\Components\Section::make('Channel Details')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('series.name')
                            ->label('Series'),
                        Infolists\Components\TextEntry::make('season.name')
                            ->label('Season'),
                        Infolists\Components\TextEntry::make('title')
                            ->label('Title'),
                        Infolists\Components\TextEntry::make('episode_num')
                            ->label('Episode'),
                        Infolists\Components\TextEntry::make('url')
                            ->label('URL')->columnSpanFull(),
                    ]),
                Infolists\Components\Section::make('Stream Info')
                    ->description('Click to load stream info')
                    ->icon('heroicon-m-wifi')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Infolists\Components\Livewire::make(ChannelStreamStats::class)
                            ->label('Stream Stats')
                            ->columnSpanFull()
                            ->lazy(),
                    ]),
            ]);
    }

    public function getTabs(): array
    {
        return [];
    }
}
