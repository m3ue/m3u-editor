<?php

namespace App\Filament\Concerns;

use App\Models\Channel;
use App\Models\Group;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

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
                $subquery = $this->getCachedDvrSetting()?->ownerChannelsSubquery();
                if (! $subquery) {
                    return [];
                }

                $searchLower = mb_strtolower($search);

                $query = Channel::whereIn('id', $subquery);
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
            ->options(function (): array {
                $dvrSetting = $this->getCachedDvrSetting();
                if (! $dvrSetting) {
                    return [];
                }

                $groupIdsSubquery = $dvrSetting->ownerChannelsSubquery('channels.group_id')->whereNotNull('channels.group_id')->distinct();

                return Group::whereIn('id', $groupIdsSubquery)
                    ->where([
                        ['name', '!=', ''],
                        ['name', '!=', null],
                    ])
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all();
            });
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

    // --- Show detail slide-over ---

    public function showDetailAction(): Action
    {
        return Action::make('showDetail')
            ->slideOver()
            ->modalHeading(fn (): string => $this->selectedShowTitle)
            ->modalContent(fn () => view('filament.pages.browse-show-detail', [
                'show' => $this->selectedShowDetail,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelAction(false);
    }

    // --- Series options form ---

    public function seriesOptionsForm(Schema $schema): Schema
    {
        return $schema
            ->statePath(null)
            ->schema([
                Section::make(__('Advanced options'))
                    ->description(fn (): string => $this->seriesHint)
                    ->compact()
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            Select::make('seriesNewOnly')
                                ->label(__('New episodes only'))
                                ->options([0 => __('No'), 1 => __('Yes')]),

                            $this->seriesChannelField(),

                            TextInput::make('seriesPriority')
                                ->label(__('Priority'))
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(99),

                            TextInput::make('seriesStartEarly')
                                ->label(__('Start early (seconds)'))
                                ->numeric()
                                ->minValue(0),

                            TextInput::make('seriesEndLate')
                                ->label(__('End late (seconds)'))
                                ->numeric()
                                ->minValue(0),

                            TextInput::make('seriesKeepLast')
                                ->label(__('Keep last N recordings'))
                                ->placeholder(__('All recordings'))
                                ->numeric()
                                ->minValue(1),
                        ]),
                    ])
                    ->footerActions([
                        Action::make('saveSeriesRule')
                            ->label(__('Save Series Rule'))
                            ->color('primary')
                            ->action(fn () => $this->recordSeriesWithOptions($this->selectedShowTitle)),
                    ]),
            ]);
    }

    protected function seriesChannelField(): Select
    {
        return Select::make('seriesChannelId')
            ->label(__('Channel'))
            ->searchable()
            ->options(fn (): array => [
                0 => __('From Original Source').($this->sourceChannelName ? ' — '.Str::limit($this->sourceChannelName, 20) : ''),
                -1 => __('Any channel'),
            ])
            ->getSearchResultsUsing(function (string $search): array {
                $searchLower = mb_strtolower($search);
                $subquery = $this->getCachedDvrSetting()?->ownerChannelsSubquery();

                $channelResults = $subquery
                    ? Channel::whereIn('id', $subquery)
                        ->where(fn (Builder $q) => $q
                            ->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                            ->orWhereRaw('LOWER(title_custom) LIKE ?', ["%{$searchLower}%"])
                            ->orWhereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                            ->orWhereRaw('LOWER(name_custom) LIKE ?', ["%{$searchLower}%"]))
                        ->orderBy('title')
                        ->limit(48)
                        ->get()
                        ->mapWithKeys(fn (Channel $c) => [$c->id => $c->title_custom ?: $c->title ?: $c->name_custom ?: $c->name])
                        ->all()
                    : [];

                // Use + to preserve int keys 0 and -1 (array_merge renumbers numeric keys)
                return [0 => __('From Original Source'), -1 => __('Any channel')] + $channelResults;
            })
            ->getOptionLabelUsing(function (mixed $value): ?string {
                $id = (int) $value;
                if ($id === 0) {
                    return __('From Original Source').($this->sourceChannelName ? ' — '.Str::limit($this->sourceChannelName, 20) : '');
                }
                if ($id === -1) {
                    return __('Any channel');
                }

                return $id > 0 ? $this->resolveChannelName($id) : null;
            })
            ->live()
            ->afterStateUpdated(function (mixed $state): void {
                $channelId = (int) $state;
                $this->seriesChannelName = $channelId > 0 ? $this->resolveChannelName($channelId) : null;
            });
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
