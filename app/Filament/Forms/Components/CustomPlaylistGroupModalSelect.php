<?php

namespace App\Filament\Forms\Components;

use App\Filament\Tables\CustomPlaylistCategoriesTable;
use App\Filament\Tables\CustomPlaylistGroupsTable;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Custom-playlist group/category picker for bouquet selections.
 *
 * The backing tables are keyed by NAME (tag names unioned with fallback provider
 * group names), which is exactly what group_selections stores - so unlike the
 * standard-target sibling there is no ID<->name round-trip and nothing can be
 * silently dropped on save. Reads the sibling `custom_playlist_id` field.
 *
 * $type: 'live' | 'vod' | 'categories'.
 */
class CustomPlaylistGroupModalSelect
{
    public static function make(string $statePath, string $type): ModalTableSelect
    {
        $isCategories = $type === 'categories';

        $selectLabel = match ($type) {
            'live' => __('Select live groups'),
            'vod' => __('Select VOD groups'),
            default => __('Select series categories'),
        };
        $modalHeading = match ($type) {
            'live' => __('Search live groups'),
            'vod' => __('Search VOD groups'),
            default => __('Search series categories'),
        };

        return ModalTableSelect::make($statePath)
            ->tableConfiguration($isCategories ? CustomPlaylistCategoriesTable::class : CustomPlaylistGroupsTable::class)
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => (bool) $get('custom_playlist_id'))
            ->multiple()
            ->tableArguments(function (Get $get) use ($isCategories, $type): array {
                $arguments = ['custom_playlist_id' => (int) $get('custom_playlist_id')];
                if (! $isCategories) {
                    $arguments['type'] = $type;
                }

                return $arguments;
            })
            ->selectAction(
                fn (Action $action) => $action
                    ->label($selectLabel)
                    ->modalHeading($modalHeading)
                    ->modalDescription(__('Includes groups you created in this custom playlist and the original source playlist groups.'))
                    ->modalSubmitActionLabel(__('Confirm selection'))
                    ->button(),
            )
            ->hintAction(
                Action::make('clear_custom_'.str_replace('.', '_', $statePath))
                    ->label(__('Clear all'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->action(fn (Set $set) => $set($statePath, []))
                    ->requiresConfirmation()
                    ->modalHeading(__('Clear selection'))
                    ->modalSubmitActionLabel(__('Clear'))
            )
            ->getOptionLabelFromRecordUsing(fn ($record): string => $record->name)
            ->getOptionLabelsUsing(fn (array $values): array => array_combine($values, $values));
    }
}
