<?php

namespace App\Filament\Actions;

use App\Filament\Resources\AioStreamsMatchProfiles\AioStreamsMatchProfileResource;
use App\Jobs\NotifyAioStreamsResolutionComplete;
use App\Jobs\ResolveAioStreamsChannel;
use App\Jobs\ResolveAioStreamsEpisode;
use App\Models\Channel;
use App\Models\Episode;
use App\Services\AioStreamsCandidateService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Log;

/**
 * Per-item "Rescan for stream candidates" for an AIOStreams-backed movie
 * (Channel) or Episode. Automatic re-runs the auto-matcher (and drops any
 * earlier manual pick); Manual fetches the current streams so the user can
 * choose which to use and drag them into failover order.
 */
final class AioStreamsRescanAction
{
    public static function make(): Action
    {
        return Action::make('rescan')
            ->tooltip(__('Rescan for stream candidates'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->button()
            ->hiddenLabel()
            ->size('sm')
            ->modalHeading(__('Rescan for stream candidates'))
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel(__('Rescan'))
            ->fillForm(fn (Channel|Episode $record): array => [
                'mode' => 'automatic',
                'profile_id' => $record instanceof Channel ? $record->aio_match_profile_id : null,
                'candidates' => [],
            ])
            // A wizard rather than a single form: the stream fetch can take many
            // seconds, and running it from a live field update left the modal's
            // submit button clickable meanwhile - a click (or Enter) queued
            // behind the fetch and saved the pre-selected streams unseen. Here
            // the fetch runs on "Next" (which shows a spinner and goes inert
            // while loading) and the submit button only exists on the last step.
            // In Automatic mode the picker step is hidden, so step one is the
            // last step and submits straight away as before.
            ->steps([
                Step::make('mode')
                    ->label(__('Stream selection'))
                    ->schema([
                        ToggleButtons::make('mode')
                            ->hiddenLabel()
                            ->grouped()
                            ->options([
                                'automatic' => __('Automatic'),
                                'manual' => __('Manual'),
                            ])
                            ->icons([
                                'automatic' => 'heroicon-o-sparkles',
                                'manual' => 'heroicon-o-hand-raised',
                            ])
                            ->helperText(fn (Get $get): string => $get('mode') === 'manual'
                                ? __('Choose the streams to use and drag them into order. The first is played, the rest are failovers. Your picks are kept when links are refreshed.')
                                : __('Let the auto-matcher pick the best streams. Replaces any manually chosen streams.'))
                            ->inline()
                            ->required()
                            ->live(),
                        // Movies only: an episode follows its series' profile, which is
                        // set from the series' own rescan action.
                        self::profileSelect()
                            ->visible(fn (Get $get, Channel|Episode $record): bool => $record instanceof Channel && $get('mode') === 'automatic'),
                    ])
                    ->afterValidation(function (Get $get, Set $set, Channel|Episode $record): void {
                        if ($get('mode') !== 'manual' || ! empty($get('candidates'))) {
                            return;
                        }

                        try {
                            $rows = app(AioStreamsCandidateService::class)->loadManualCandidates($record);
                        } catch (\Throwable $e) {
                            Log::warning('AioStreamsRescanAction: fetching streams failed', [
                                'record' => $record::class.':'.$record->id,
                                'exception' => $e->getMessage(),
                            ]);
                            $rows = [];
                        }

                        if ($rows === []) {
                            Notification::make()->danger()->title(__('No streams found for this title right now'))->send();

                            throw new Halt;
                        }

                        $set('candidates', $rows);
                    }),
                Step::make('streams')
                    ->label(__('Pick streams'))
                    ->visible(fn (Get $get): bool => $get('mode') === 'manual')
                    ->schema([
                        self::candidatesRepeater(),
                    ]),
            ])
            ->action(function (Channel|Episode $record, array $data, Action $action): void {
                if (($data['mode'] ?? 'automatic') === 'manual') {
                    self::applyManual($record, $data['candidates'] ?? [], $action);

                    return;
                }

                self::rescanAutomatically($record, $data['profile_id'] ?? null);
            });
    }

    /**
     * Per-title auto-selection profile override; empty uses the integration's
     * default for the content type.
     */
    public static function profileSelect(): Select
    {
        return Select::make('profile_id')
            ->label(__('Match profile'))
            ->searchable()
            ->options(fn (): array => AioStreamsMatchProfileResource::profileOptions())
            ->placeholder(__('Integration default'))
            ->helperText(__('Rules for which streams the auto-matcher picks for this title. Manage profiles under AIOStreams Profiles.'));
    }

    /**
     * Read-only details for one stream row (manual picker and profile tester):
     * the parsed tags, why the title's profile would skip it, and the addon's
     * own description. Reads the row's name/details/tags/excluded state.
     *
     * @param  bool  $showExclusion  Off where the caller shows its own outcome badge.
     * @return array<int, Component>
     */
    public static function candidateInfo(bool $showExclusion = true): array
    {
        return [
            Hidden::make('name')->dehydrated(false),
            Hidden::make('details')->dehydrated(false),
            Hidden::make('tags')->dehydrated(false),
            Hidden::make('excluded')->dehydrated(false),
            Text::make(fn (Get $get): string => __('Excluded by profile: :reason', ['reason' => (string) $get('excluded')]))
                ->badge()
                ->color('warning')
                ->visible(fn (Get $get): bool => $showExclusion && filled($get('excluded'))),
            Text::make(fn (Get $get): string => (string) $get('tags'))
                ->weight(FontWeight::Medium),
            Text::make(fn (Get $get) => AioStreamsCandidateService::detailsHtml($get('details'))),
        ];
    }

    private static function candidatesRepeater(): Repeater
    {
        return Repeater::make('candidates')
            ->hiddenLabel()
            ->schema([
                Hidden::make('key'),
                Toggle::make('selected')
                    ->label(__('Use this stream')),
                ...self::candidateInfo(),
            ])
            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
            ->addable(false)
            ->deletable(false)
            ->reorderable()
            ->reorderableWithDragAndDrop()
            ->reorderableWithButtons()
            ->columnSpanFull();
    }

    /**
     * @param  array<array-key, array{key?: string, selected?: bool}>  $rows  In the user's dragged order.
     */
    private static function applyManual(Channel|Episode $record, array $rows, Action $action): void
    {
        $orderedKeys = collect($rows)
            ->filter(fn (array $row) => ! empty($row['selected']) && ! empty($row['key']))
            ->pluck('key')
            ->values()
            ->all();

        if ($orderedKeys === []) {
            Notification::make()->warning()->title(__('Select at least one stream'))->send();
            $action->halt();
        }

        $stored = app(AioStreamsCandidateService::class)->applyManualSelection($record, $orderedKeys);

        if ($stored === 0) {
            Notification::make()->danger()->title(__('The stream list expired, please rescan again'))->send();

            return;
        }

        Notification::make()->success()->title(__(':count stream(s) saved', ['count' => $stored]))->send();
    }

    private static function rescanAutomatically(Channel|Episode $record, mixed $profileId = null): void
    {
        app(AioStreamsCandidateService::class)->clearManualSelection($record);

        if ($record instanceof Channel) {
            $record->update([
                'aio_match_profile_id' => filled($profileId) ? (int) $profileId : null,
                'aio_resolution_status' => 'pending',
            ]);

            ResolveAioStreamsChannel::dispatch($record->id);
            NotifyAioStreamsResolutionComplete::dispatch([$record->id], [], $record->user_id, $record->title_custom ?? $record->title ?? $record->name)
                ->delay(now()->addSeconds(15));
        } else {
            $record->update(['aio_resolution_status' => 'pending']);

            ResolveAioStreamsEpisode::dispatch($record->id);
            NotifyAioStreamsResolutionComplete::dispatch([], [$record->id], $record->user_id, $record->title)
                ->delay(now()->addSeconds(15));
        }

        Notification::make()->success()->title(__('Rescanning stream, check back shortly'))->send();
    }
}
