<?php

namespace App\Filament\Concerns;

use App\Models\Channel;
use App\Models\Group;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;

trait HasBrowseShowsFiltersForm
{
    /**
     * Searchable channel picker, server-side filtered.
     * Reads $this->getCachedDvrSetting() so it works in both auth and guest panels.
     * Callers may chain ->disabled() for panels where dvr_setting_id is selectable.
     */
    protected function channelFilterField(): Select
    {
        return Select::make('channel_id')
            ->label(__('Channel'))
            ->placeholder(__('— Any —'))
            ->searchable()
            ->getSearchResultsUsing(function (string $search): array {
                $playlistId = $this->getCachedDvrSetting()?->playlist_id;
                if (! $playlistId) {
                    return [];
                }

                $searchLower = mb_strtolower($search);

                $query = Channel::where('playlist_id', $playlistId);
                if (! $this->shouldIncludeDisabledChannels()) {
                    $query->where('enabled', true);
                }

                return $query
                    ->where(fn (Builder $q) => $q
                        ->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                        ->orWhereRaw('LOWER(title_custom) LIKE ?', ["%{$searchLower}%"])
                        ->orWhereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                        ->orWhereRaw('LOWER(name_custom) LIKE ?', ["%{$searchLower}%"]))
                    ->orderBy('title')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn (Channel $c) => [
                        $c->id => $c->title_custom ?: $c->title ?: $c->name_custom ?: $c->name,
                    ])
                    ->all();
            })
            ->getOptionLabelUsing(fn ($value): ?string => $value ? $this->resolveChannelName((int) $value) : null);
    }

    /**
     * Group filter select, options loaded from the current DVR setting's playlist.
     * Callers may chain ->disabled() for panels where dvr_setting_id is selectable.
     */
    protected function groupFilterField(): Select
    {
        return Select::make('group_id')
            ->label(__('Group'))
            ->placeholder(__('— Any —'))
            ->searchable()
            ->options(fn (): array => Group::where('playlist_id', $this->getCachedDvrSetting()?->playlist_id ?? 0)
                ->where([
                    ['name', '!=', ''],
                    ['name', '!=', null],
                ])
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all());
    }

    protected function keywordFilterField(): TextInput
    {
        return TextInput::make('keyword')
            ->label(__('Title Keyword'))
            ->placeholder(__('e.g. Breaking Bad'));
    }

    protected function categoryFilterField(): TextInput
    {
        return TextInput::make('category')
            ->label(__('Category'))
            ->placeholder(__('e.g. Drama'));
    }

    protected function descriptionKeywordFilterField(): TextInput
    {
        return TextInput::make('description_keyword')
            ->label(__('Description Keyword'))
            ->placeholder(__('e.g. detective'));
    }

    protected function daysFilterField(): Select
    {
        return Select::make('days')
            ->label(__('Look-ahead Window'))
            ->options([
                7 => __('7 days'),
                14 => __('14 days'),
                30 => __('30 days'),
            ]);
    }

    private function resolveChannelName(?int $channelId): ?string
    {
        if (! $channelId) {
            return null;
        }

        $channel = Channel::find($channelId, ['id', 'title', 'title_custom', 'name', 'name_custom']);

        return $channel
            ? ($channel->title_custom ?: $channel->title ?: $channel->name_custom ?: $channel->name) ?: null
            : null;
    }

    private function shouldIncludeDisabledChannels(): bool
    {
        return $this->getCachedDvrSetting()?->include_disabled_channels ?? false;
    }
}
