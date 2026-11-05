<?php

namespace App\Filament\Tables;

use App\Services\Sports\SportsService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The leagues Sports serves, within any limit set in Settings, to pick from.
 * They come from the providers' catalogues rather than the database, so
 * searching, sorting and filtering happen here.
 */
class SportsLeaguesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->records(function (?string $sortColumn, ?string $sortDirection, ?string $search, array $filters, int $page, int $recordsPerPage) use ($table): LengthAwarePaginator {
                $leagues = collect(app(SportsService::class)->leagues())
                    ->map(fn (array $league): array => [...$league, 'key' => $league['id']]);

                if (filled($search)) {
                    $term = mb_strtolower($search);
                    $leagues = $leagues->filter(fn (array $league): bool => str_contains(
                        mb_strtolower($league['name'].' '.$league['abbreviation'].' '.$league['sport_name']),
                        $term,
                    ));
                }
                $sport = $filters['sport']['value'] ?? null;
                if (filled($sport)) {
                    $leagues = $leagues->where('sport', $sport);
                }
                $selection = $filters['selection']['value'] ?? null;
                if (filled($selection)) {
                    $selected = (array) $table->getLivewire()->state;
                    $wantsSelected = filter_var($selection, FILTER_VALIDATE_BOOLEAN);
                    $leagues = $leagues->filter(fn (array $league): bool => in_array($league['id'], $selected, true) === $wantsSelected);
                }

                $column = in_array($sortColumn, ['name', 'abbreviation', 'sport_name'], true) ? $sortColumn : 'name';
                $leagues = $leagues->sortBy($column, SORT_NATURAL | SORT_FLAG_CASE, $sortDirection === 'desc')->values();

                return new LengthAwarePaginator(
                    $leagues->forPage($page, $recordsPerPage)->keyBy('key'),
                    $leagues->count(),
                    $recordsPerPage,
                    $page,
                );
            })
            ->searchable()
            ->defaultSort('name')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->selectCurrentPageOnly()
            ->columns([
                TextColumn::make('name')
                    ->label(__('League'))
                    ->sortable(),
                TextColumn::make('abbreviation')
                    ->label(__('Abbreviation'))
                    ->sortable(),
                TextColumn::make('sport_name')
                    ->label(__('Sport'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('sport')
                    ->label(__('Sport'))
                    ->options(fn (): array => collect(app(SportsService::class)->leagues())
                        ->pluck('sport_name', 'sport')
                        ->sort()
                        ->all()),
                TernaryFilter::make('selection')
                    ->label(__('Leagues'))
                    ->placeholder(__('All leagues'))
                    ->trueLabel(__('Selected only'))
                    ->falseLabel(__('Unselected only')),
            ]);
    }
}
