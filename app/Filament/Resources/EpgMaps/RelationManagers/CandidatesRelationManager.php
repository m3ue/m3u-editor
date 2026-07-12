<?php

namespace App\Filament\Resources\EpgMaps\RelationManagers;

use App\Enums\EpgMapCandidateStatus;
use App\Models\Channel;
use App\Models\EpgChannel;
use App\Models\EpgMapCandidate;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class CandidatesRelationManager extends RelationManager
{
    protected static string $relationship = 'candidates';

    protected static ?string $title = 'Review Candidates';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Candidate review'))
            ->description(__('Built from EPG candidates for unresolved channels in this map. Use bulk actions to confirm the top match for many rows at once, or change the candidate for a single channel.'))
            ->deferLoading()
            ->defaultSort('top_confidence', 'desc')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->defaultPaginationPageOption(25)
            ->paginated([10, 25, 50, 100, 200])
            ->columns([
                TextColumn::make('original_name')
                    ->label(__('Channel'))
                    ->description(fn (EpgMapCandidate $record): ?string => $record->normalized_name ?: null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('top_matched_value')
                    ->label(__('Matched EPG value'))
                    ->description(fn (EpgMapCandidate $record): ?string => $record->top_normalized_value ?: null)
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('top_confidence')
                    ->label(__('Confidence'))
                    ->badge()
                    ->color(fn (EpgMapCandidate $record): string => match (true) {
                        $record->top_confidence >= 80 => 'success',
                        $record->top_confidence >= 60 => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (EpgMapCandidate $record): string => "{$record->top_confidence}%")
                    ->sortable(),
                TextColumn::make('top_reason')
                    ->label(__('Reason'))
                    ->toggleable(),
                IconColumn::make('is_exact')
                    ->label(__('Exact'))
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('automatic_match')
                    ->label(__('Auto match'))
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('alternatives')
                    ->label(__('Alternatives'))
                    ->state(fn (EpgMapCandidate $record): int => count($record->alternatives ?? []))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (EpgMapCandidateStatus $state): string => $state->getColor())
                    ->formatStateUsing(fn (EpgMapCandidateStatus $state): string => $state->getLabel())
                    ->sortable(),
                TextColumn::make('applied_at')
                    ->label(__('Applied'))
                    ->dateTime()
                    ->since()
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('apply')
                    ->label(__('Apply'))
                    ->icon('heroicon-s-check')
                    ->button()
                    ->requiresConfirmation()
                    ->modalDescription(fn (EpgMapCandidate $record): string => __('Set :channel to :epg?', [
                        'channel' => $record->original_name,
                        'epg' => $record->top_matched_value ?: $record->epgChannel?->display_name ?: $record->epgChannel?->name ?: __('the matched EPG channel'),
                    ]))
                    ->visible(fn (EpgMapCandidate $record): bool => $record->epg_channel_id !== null
                        && $record->status === EpgMapCandidateStatus::Pending
                        && static::canReview($record))
                    ->action(fn (EpgMapCandidate $record) => static::applyCandidate($record)),
                Action::make('changeCandidate')
                    ->label(__('Change'))
                    ->icon('heroicon-s-arrow-path')
                    ->button()
                    ->color('gray')
                    ->modalWidth(Width::TwoExtraLarge)
                    ->modalSubmitActionLabel(__('Apply selected candidate'))
                    ->schema(fn (EpgMapCandidate $record): array => static::alternativeSchema($record))
                    ->visible(fn (EpgMapCandidate $record): bool => $record->epg_channel_id !== null
                        && count($record->alternatives ?? []) > 0
                        && $record->status === EpgMapCandidateStatus::Pending
                        && static::canReview($record))
                    ->action(fn (array $data, EpgMapCandidate $record) => static::applyAlternative($record, (int) ($data['epg_channel_id'] ?? 0))),
                Action::make('skip')
                    ->label(__('Skip'))
                    ->icon('heroicon-s-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(fn (EpgMapCandidate $record): string => __('Mark :channel as skipped? You can build candidates again later if needed.', [
                        'channel' => $record->original_name,
                    ]))
                    ->visible(fn (EpgMapCandidate $record): bool => $record->status === EpgMapCandidateStatus::Pending
                        && static::canReview($record))
                    ->action(fn (EpgMapCandidate $record) => $record->update([
                        'status' => EpgMapCandidateStatus::Skipped,
                        'applied_at' => now(),
                    ])),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                BulkActionGroup::make([
                    Action::make('applyTop')
                        ->label(__('Apply top candidate'))
                        ->icon('heroicon-s-check')
                        ->requiresConfirmation()
                        ->modalDescription(__('Apply the top-ranked EPG channel to every selected channel. Existing mappings are never overwritten.'))
                        ->visible(fn (): bool => static::ownerMatchesAuth($this->ownerRecord))
                        ->action(fn (Collection $records) => static::applyMany($records)),
                    Action::make('skip')
                        ->label(__('Mark as skipped'))
                        ->icon('heroicon-s-x-mark')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => static::ownerMatchesAuth($this->ownerRecord))
                        ->action(fn (Collection $records) => static::skipMany($records)),
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => static::ownerMatchesAuth($this->ownerRecord)),
                ]),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(collect(EpgMapCandidateStatus::cases())->mapWithKeys(fn ($case): array => [$case->value => $case->getLabel()])->all()),
                TernaryFilter::make('automatic_match')
                    ->label(__('Automatic match')),
                TernaryFilter::make('is_exact')
                    ->label(__('Exact normalized')),
                TernaryFilter::make('has_alternatives')
                    ->label(__('Has alternatives'))
                    ->placeholder(__('Any'))
                    ->trueLabel(__('Yes'))
                    ->falseLabel(__('No'))
                    ->query(fn (Builder $query, mixed $state): Builder => match ($state) {
                        true => $query->whereNotNull('alternatives'),
                        false => $query->whereNull('alternatives'),
                        default => $query,
                    }),
            ]);
    }

    protected static function ownerMatchesAuth($ownerRecord): bool
    {
        return $ownerRecord !== null
            && $ownerRecord->user_id === auth()->id()
            && $ownerRecord->epg?->user_id === auth()->id()
            && $ownerRecord->playlist_id !== null;
    }

    protected static function canReview(EpgMapCandidate $record): bool
    {
        $map = $record->epgMap;

        return $map !== null
            && $map->user_id === auth()->id()
            && $map->epg?->user_id === auth()->id()
            && $map->playlist_id !== null;
    }

    /** @return array<int, mixed> */
    protected static function alternativeSchema(EpgMapCandidate $record): array
    {
        $topRow = [
            'epg_channel_id' => $record->epg_channel_id,
            'display_name' => $record->top_matched_value,
            'confidence' => $record->top_confidence,
            'reason' => $record->top_reason,
            'normalized_value' => $record->top_normalized_value,
        ];

        $allCandidates = collect([$topRow, ...($record->alternatives ?? [])])
            ->filter(fn (array $c): bool => $c['epg_channel_id'] !== null);

        $options = $allCandidates->mapWithKeys(fn (array $candidate): array => [
            $candidate['epg_channel_id'] => __(':name — :confidence% — :reason', [
                'name' => $candidate['display_name'] ?: __('(no name)'),
                'confidence' => $candidate['confidence'],
                'reason' => $candidate['reason'],
            ]),
        ])->all();

        return [
            Radio::make('epg_channel_id')
                ->label($record->original_name)
                ->options($options)
                ->default($record->epg_channel_id)
                ->required(),
        ];
    }

    protected static function applyCandidate(EpgMapCandidate $record): void
    {
        if (! static::canReview($record) || $record->epg_channel_id === null) {
            return;
        }

        static::applyChoices($record->epgMap, [$record->channel_id => $record->epg_channel_id]);

        $record->update([
            'status' => EpgMapCandidateStatus::Applied,
            'applied_at' => now(),
        ]);

        Notification::make()
            ->success()
            ->title(__('Mapping applied'))
            ->body(__(':channel mapped.', ['channel' => $record->original_name]))
            ->send();
    }

    protected static function applyMany(iterable $records): void
    {
        $map = null;
        $choices = [];
        $candidateIds = [];

        foreach ($records as $record) {
            if (! $record instanceof EpgMapCandidate) {
                continue;
            }
            if ($record->status !== EpgMapCandidateStatus::Pending || $record->epg_channel_id === null) {
                continue;
            }
            if (! static::canReview($record)) {
                continue;
            }
            $map ??= $record->epgMap;
            $choices[$record->channel_id] = $record->epg_channel_id;
            $candidateIds[] = $record->id;
        }

        if (empty($choices) || $map === null) {
            Notification::make()
                ->warning()
                ->title(__('Nothing to apply'))
                ->body(__('Only pending rows with a top candidate are eligible.'))
                ->send();

            return;
        }

        static::applyChoices($map, $choices);

        EpgMapCandidate::query()
            ->whereIn('id', $candidateIds)
            ->update([
                'status' => EpgMapCandidateStatus::Applied,
                'applied_at' => now(),
            ]);

        Notification::make()
            ->success()
            ->title(trans_choice(':count mapping applied.|:count mappings applied.', count($choices), ['count' => count($choices)]))
            ->send();
    }

    protected static function applyAlternative(EpgMapCandidate $record, int $epgChannelId): void
    {
        if (! static::canReview($record) || $epgChannelId <= 0) {
            return;
        }

        $validIds = collect([$record->epg_channel_id, ...collect($record->alternatives ?? [])
            ->pluck('epg_channel_id')
            ->all(),
        ])->flip();

        if (! $validIds->has($epgChannelId)) {
            Notification::make()
                ->danger()
                ->title(__('Invalid candidate'))
                ->body(__('That EPG channel is not part of this candidate review row.'))
                ->send();

            return;
        }

        $selected = collect([
            [
                'epg_channel_id' => $record->epg_channel_id,
                'display_name' => $record->top_matched_value,
                'confidence' => $record->top_confidence,
                'reason' => $record->top_reason,
                'normalized_value' => $record->top_normalized_value,
            ],
            ...($record->alternatives ?? []),
        ])->firstWhere('epg_channel_id', $epgChannelId);

        static::applyChoices($record->epgMap, [$record->channel_id => $epgChannelId]);

        $record->update([
            'epg_channel_id' => $epgChannelId,
            'top_confidence' => $selected['confidence'] ?? $record->top_confidence,
            'top_reason' => $selected['reason'] ?? $record->top_reason,
            'top_matched_value' => $selected['display_name'] ?? $record->top_matched_value,
            'top_normalized_value' => $selected['normalized_value'] ?? $record->top_normalized_value,
            'status' => EpgMapCandidateStatus::Applied,
            'applied_at' => now(),
        ]);

        Notification::make()
            ->success()
            ->title(__('Mapping applied'))
            ->body(__(':channel mapped.', ['channel' => $record->original_name]))
            ->send();
    }

    protected static function skipMany(iterable $records): void
    {
        $ids = [];
        foreach ($records as $record) {
            if (! $record instanceof EpgMapCandidate) {
                continue;
            }
            if (! static::canReview($record) || $record->status !== EpgMapCandidateStatus::Pending) {
                continue;
            }
            $ids[] = $record->id;
        }

        if ($ids === []) {
            return;
        }

        EpgMapCandidate::query()
            ->whereIn('id', $ids)
            ->update([
                'status' => EpgMapCandidateStatus::Skipped,
                'applied_at' => now(),
            ]);

        Notification::make()
            ->success()
            ->title(trans_choice(':count row marked skipped.|:count rows marked skipped.', count($ids), ['count' => count($ids)]))
            ->send();
    }

    /**
     * Persist channel.epg_channel_id for the supplied choices, scoped to
     * channels that are still unmapped and owned by the same user as the map.
     *
     * @param  array<int, int>  $choices  channel_id => epg_channel_id
     */
    protected static function applyChoices($map, array $choices): void
    {
        $candidateIds = array_values(array_unique(array_map('intval', $choices)));
        $validCandidateIds = EpgChannel::query()
            ->where('user_id', $map->user_id)
            ->where('epg_id', $map->epg_id)
            ->whereIn('id', $candidateIds)
            ->pluck('id')
            ->flip();

        foreach ($choices as $channelId => $epgChannelId) {
            if (! $epgChannelId || ! $validCandidateIds->has((int) $epgChannelId)) {
                continue;
            }

            Channel::query()
                ->whereKey((int) $channelId)
                ->where('user_id', $map->user_id)
                ->where('playlist_id', $map->playlist_id)
                ->where('is_vod', false)
                ->where('epg_map_enabled', true)
                ->whereNull('epg_channel_id')
                ->update(['epg_channel_id' => (int) $epgChannelId]);
        }
    }
}
