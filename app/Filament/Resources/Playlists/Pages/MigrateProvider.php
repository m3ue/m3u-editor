<?php

namespace App\Filament\Resources\Playlists\Pages;

use App\Filament\Resources\Playlists\PlaylistResource;
use App\Jobs\CopyAttributesToPlaylist;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\ProviderMigrationPlanRow;
use App\Services\ChannelMatchResolver;
use App\Services\ProviderMigrationPlanner;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Interactive "provider migration": preview how an expired playlist's curated Live TV lineup
 * maps onto a working replacement playlist, resolve ambiguous / unmatched channels by hand,
 * optionally preserve EPG mappings, then apply the reviewed plan onto the working playlist.
 *
 * The preview is materialized into `provider_migration_plan_rows` so the review screen pages,
 * searches and sorts server-side instead of rendering every channel at once.
 */
class MigrateProvider extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = PlaylistResource::class;

    protected string $view = 'filament.resources.playlists.pages.migrate-provider';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Identifies the current preview's rows in provider_migration_plan_rows. */
    public ?string $sessionKey = null;

    /** Plan fingerprint captured at preview time, revalidated server-side on apply. */
    public ?string $planFingerprint = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();
        abort_unless($this->record->user_id === auth()->id(), 403);
        abort_if($this->record->is_network_playlist || $this->record->isMediaServerPlaylist(), 404);

        $this->pruneStalePlanRows();

        $this->form->fill([
            'target_playlist_id' => null,
            'passes' => ChannelMatchResolver::DEFAULT_PASSES,
            'fields' => ['enabled', 'group', 'sort', 'channel'],
            'preserve_epg' => true,
            'overwrite' => true,
            'disable_target_only' => false,
        ]);
    }

    public function getTitle(): string
    {
        return __('Migrate Provider');
    }

    public function getSubheading(): ?string
    {
        return __('Move the curated Live TV lineup from ":name" onto a working replacement playlist. The replacement keeps its own stream URLs and provider identity.', ['name' => $this->record->name]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Configure'))
                    ->description(__('Choose the replacement playlist and what to carry across, then build the preview.'))
                    ->schema([
                        Select::make('target_playlist_id')
                            ->label(__('Replacement playlist'))
                            ->helperText(__('The working playlist whose channels should receive this lineup.'))
                            ->options(fn (): array => Playlist::query()
                                ->where('user_id', auth()->id())
                                ->where('id', '!=', $this->record->id)
                                ->where('is_network_playlist', false)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                        CheckboxList::make('passes')
                            ->label(__('Match passes (in order)'))
                            ->options([
                                ChannelMatchResolver::PASS_TVG_ID => __('Unique shared TVG-ID / Stream ID'),
                                ChannelMatchResolver::PASS_NAME => __('Unique normalized channel name'),
                                ChannelMatchResolver::PASS_TITLE => __('Unique normalized channel title'),
                            ])
                            ->default(ChannelMatchResolver::DEFAULT_PASSES)
                            ->required()
                            ->columns(1),
                        CheckboxList::make('fields')
                            ->label(__('Configuration to copy onto matched channels'))
                            ->options([
                                'enabled' => __('Enabled / disabled state'),
                                'group' => __('Group assignment and order'),
                                'sort' => __('Channel sort order'),
                                'channel' => __('Channel number'),
                                'shift' => __('TVG shift'),
                                'name' => __('Name override'),
                                'title' => __('Title override'),
                                'logo' => __('Logo override'),
                                'station_id' => __('Station ID'),
                            ])
                            ->default(['enabled', 'group', 'sort', 'channel'])
                            ->required()
                            ->columns(2),
                        Toggle::make('preserve_epg')
                            ->label(__('Preserve EPG mappings'))
                            ->helperText(__('Re-point matched channels at the replacement provider\'s equivalent EPG channel where one exists, otherwise copy the existing mapping. An expired provider\'s EPG may no longer publish programme data.'))
                            ->default(true),
                        Toggle::make('overwrite')
                            ->label(__('Overwrite existing values'))
                            ->helperText(__('Keep this on for a lineup migration. When off, only empty fields on the replacement channels are filled, so already-set values like the enabled state, channel number and sort order are left untouched.'))
                            ->default(true),
                        Toggle::make('disable_target_only')
                            ->label(__('Disable channels that are not in this lineup'))
                            ->helperText(__('Turns off replacement channels with no match here, for a strict curated-lineup migration. Nothing is deleted.'))
                            ->default(false),
                    ]),
                Action::make('buildPreview')
                    ->label(fn (): string => $this->sessionKey ? __('Rebuild preview') : __('Build preview'))
                    ->icon('heroicon-o-magnifying-glass')
                    ->action(fn () => $this->buildPreview()),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }

    /**
     * The "Apply migration" action, shown above the review table.
     */
    protected function applyAction(): Action
    {
        return Action::make('apply')
            ->label(__('Apply migration'))
            ->icon('heroicon-o-rocket-launch')
            ->color('primary')
            ->visible(fn (): bool => filled($this->sessionKey))
            ->requiresConfirmation()
            ->modalDescription(__('Only the rows marked "Include" that point at a replacement channel are migrated. Existing clients keep the same playlist output, now served from the replacement provider. Custom Playlist membership, per-custom-playlist channel numbers and tag-based groups are not part of this migration.'))
            ->modalSubmitActionLabel(__('Apply migration'))
            ->action(fn () => $this->apply());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->sessionKey
                ? ProviderMigrationPlanRow::query()->where('session_key', $this->sessionKey)
                : ProviderMigrationPlanRow::query()->whereRaw('1 = 0'))
            ->headerActions([
                Action::make('buildPreview')
                    ->label(fn (): string => $this->sessionKey ? __('Rebuild preview') : __('Build preview'))
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->action(fn () => $this->buildPreview()),
                $this->applyAction(),
            ])
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->emptyStateIcon('heroicon-o-magnifying-glass')
            ->emptyStateHeading(__('No preview available'))
            ->emptyStateDescription(__('No preview is available yet. Choose a replacement playlist and build the preview to see how the lineup will migrate.'))
            // Included rows first, then alphabetical. A single defaultSort() call only
            // takes one column, so multi-column ordering has to go through a closure.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('include')
                ->orderBy('source_name'))
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('source_name')
                    ->label(__('Source channel'))
                    ->description(fn (ProviderMigrationPlanRow $record): ?string => $record->source_stream_id)
                    ->searchable(['source_name', 'source_stream_id'])
                    ->sortable(),
                ToggleColumn::make('include')
                    ->label(__('Include')),
                TextColumn::make('source_group')
                    ->label(__('Source group'))
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('bucket')
                    ->label(__('Match'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'matched' => 'success',
                        'ambiguous' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('matched_target_name')
                    ->label(__('Replacement channel'))
                    ->description(fn (ProviderMigrationPlanRow $record): ?string => $record->matched_target_stream_id)
                    ->placeholder('-')
                    ->searchable(['matched_target_name', 'matched_target_stream_id']),
                TextColumn::make('match_pass')
                    ->label(__('Matched by'))
                    ->formatStateUsing(fn (?string $state): string => $state ? str_replace('_', ' ', $state) : '-')
                    ->toggleable(),

                TextColumn::make('epg_status')
                    ->label(__('EPG'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ok' => 'success',
                        'conflict' => 'warning',
                        'unavailable' => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(),
                ToggleColumn::make('epg_confirmed')
                    ->label(__('Replace EPG'))
                    ->disabled(fn (ProviderMigrationPlanRow $record): bool => $record->epg_status !== 'conflict')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('bucket')
                    ->label(__('Match'))
                    ->options([
                        'matched' => __('Matched'),
                        'ambiguous' => __('Ambiguous'),
                        'unmatched' => __('Unmatched'),
                    ]),
                TernaryFilter::make('include')
                    ->label(__('Included')),
            ])
            ->recordActions([
                Action::make('changeMatch')
                    ->label(__('Change match'))
                    ->icon('heroicon-o-arrows-right-left')
                    ->fillForm(fn (ProviderMigrationPlanRow $record): array => [
                        'matched_target_channel_id' => $record->matched_target_channel_id,
                    ])
                    ->schema([
                        Select::make('matched_target_channel_id')
                            ->label(__('Replacement channel'))
                            ->placeholder(__('Not migrated'))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchTargetChannels($search))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->targetChannelLabel((int) $value))
                            ->helperText(fn (ProviderMigrationPlanRow $record): ?string => $record->bucket === 'ambiguous'
                                ? __('Candidates: :list', ['list' => $this->candidateLabels($record)])
                                : null),
                    ])
                    ->action(function (array $data, ProviderMigrationPlanRow $record): void {
                        $this->applyMatchChoice($record, $data['matched_target_channel_id'] ?? null);
                    })->button()->size('sm'),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkAction::make('include')
                    ->label(__('Include selected'))
                    ->icon('heroicon-o-check')
                    ->action(fn (Collection $records) => ProviderMigrationPlanRow::query()
                        ->whereKey($records->pluck('id'))
                        ->update(['include' => true]))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('exclude')
                    ->label(__('Exclude selected'))
                    ->icon('heroicon-o-x-mark')
                    ->action(fn (Collection $records) => ProviderMigrationPlanRow::query()
                        ->whereKey($records->pluck('id'))
                        ->update(['include' => false]))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /**
     * Run the planner and materialize its rows for the review table.
     */
    public function buildPreview(): void
    {
        $this->form->validate();
        $target = $this->resolveTarget();

        $plan = app(ProviderMigrationPlanner::class)->plan($this->record, $target->id, [
            'passes' => $this->data['passes'] ?? ChannelMatchResolver::DEFAULT_PASSES,
            'preserve_epg' => (bool) ($this->data['preserve_epg'] ?? false),
        ]);

        $this->planFingerprint = $plan['fingerprint'];
        $sessionKey = (string) Str::uuid();

        // A user only ever reviews one migration at a time: drop all of their previous rows,
        // and sweep anyone's abandoned previews so the table cannot accumulate stale data.
        ProviderMigrationPlanRow::query()
            ->where('user_id', auth()->id())
            ->delete();
        $this->pruneStalePlanRows();

        $now = now();
        $base = [
            'session_key' => $sessionKey,
            'user_id' => auth()->id(),
            'source_playlist_id' => $this->record->id,
            'target_playlist_id' => $target->id,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $insert = [];
        foreach ($plan['matched'] as $row) {
            $epg = $row['epg'] ?? null;
            $insert[] = $base + [
                'source_channel_id' => $row['source']['id'],
                'source_name' => $row['source']['name'],
                'source_stream_id' => $row['source']['stream_id'],
                'source_group' => $row['source']['group'],
                'suggested_target_channel_id' => $row['target']['id'],
                'matched_target_channel_id' => $row['target']['id'],
                'matched_target_name' => $row['target']['name'],
                'matched_target_stream_id' => $row['target']['stream_id'],
                'match_pass' => $row['match_pass'],
                'bucket' => 'matched',
                'candidate_target_channel_ids' => null,
                'include' => true,
                'epg_channel_id' => ($epg && in_array($epg['status'] ?? '', ['ok', 'conflict'], true))
                    ? ($epg['proposed_epg_channel_id'] ?? null)
                    : null,
                'epg_status' => $epg['status'] ?? 'none',
                'epg_confirmed' => false,
            ];
        }
        foreach ($plan['ambiguous'] as $row) {
            $insert[] = $base + [
                'source_channel_id' => $row['source']['id'],
                'source_name' => $row['source']['name'],
                'source_stream_id' => $row['source']['stream_id'],
                'source_group' => $row['source']['group'],
                'suggested_target_channel_id' => null,
                'matched_target_channel_id' => null,
                'matched_target_name' => null,
                'matched_target_stream_id' => null,
                'match_pass' => $row['match_pass'],
                'bucket' => 'ambiguous',
                'candidate_target_channel_ids' => json_encode(collect($row['candidates'])->pluck('id')->all()),
                'include' => false,
                'epg_channel_id' => null,
                'epg_status' => 'none',
                'epg_confirmed' => false,
            ];
        }
        foreach ($plan['unmatched_source'] as $row) {
            $insert[] = $base + [
                'source_channel_id' => $row['source']['id'],
                'source_name' => $row['source']['name'],
                'source_stream_id' => $row['source']['stream_id'],
                'source_group' => $row['source']['group'],
                'suggested_target_channel_id' => null,
                'matched_target_channel_id' => null,
                'matched_target_name' => null,
                'matched_target_stream_id' => null,
                'match_pass' => null,
                'bucket' => 'unmatched',
                'candidate_target_channel_ids' => null,
                'include' => false,
                'epg_channel_id' => null,
                'epg_status' => 'none',
                'epg_confirmed' => false,
            ];
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            ProviderMigrationPlanRow::query()->insert($chunk);
        }

        $this->sessionKey = $sessionKey;
        $this->resetTable();

        // Bring the freshly built review table into view.
        $this->js(<<<'JS'
            window.requestAnimationFrame(() => {
                document.getElementById('migration-review-table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        JS);

        Notification::make()
            ->success()
            ->title(__('Preview ready'))
            ->body(trans_choice(
                '{1}:matched of :total channel matched. Review and apply below.|[2,*]:matched of :total channels matched. Review and apply below.',
                count($insert),
                ['matched' => count($plan['matched']), 'total' => count($insert)],
            ))
            ->send();
    }

    /**
     * Delete any preview rows older than the short review window. Called on every page visit
     * and before each rebuild so the scratch table never accumulates stale data.
     */
    protected function pruneStalePlanRows(): void
    {
        ProviderMigrationPlanRow::query()
            ->where('created_at', '<', now()->subHours(2))
            ->delete();
    }

    public function apply(): void
    {
        $target = $this->resolveTarget();
        $resolvedMap = $this->buildResolvedMap();

        if (empty($resolvedMap)) {
            Notification::make()
                ->warning()
                ->title(__('Nothing to migrate'))
                ->body(__('No channels are selected for migration.'))
                ->send();

            return;
        }

        app(Dispatcher::class)->dispatch(new CopyAttributesToPlaylist(
            source: $this->record,
            targetId: $target->id,
            channelAttributes: $this->data['fields'] ?? [],
            channelMatchAttributes: [],
            overwrite: (bool) ($this->data['overwrite'] ?? true),
            migrationMode: true,
            preserveEpg: (bool) ($this->data['preserve_epg'] ?? false),
            disableTargetOnly: (bool) ($this->data['disable_target_only'] ?? false),
            resolvedMap: $resolvedMap,
            planFingerprint: $this->planFingerprint,
        ));

        if ($this->sessionKey) {
            ProviderMigrationPlanRow::query()->where('session_key', $this->sessionKey)->delete();
        }

        Notification::make()
            ->success()
            ->title(__('Provider migration started'))
            ->body(__('The lineup is being migrated onto ":name" in the background. You will be notified on completion.', ['name' => $target->name]))
            ->send();

        $this->redirect(PlaylistResource::getUrl('view', ['record' => $this->record]));
    }

    /**
     * @return array<int, array{source_id: int, target_id: int, epg_channel_id: int|null, epg_confirmed: bool}>
     */
    private function buildResolvedMap(): array
    {
        if (! $this->sessionKey) {
            return [];
        }

        $preserveEpg = (bool) ($this->data['preserve_epg'] ?? false);
        $map = [];

        ProviderMigrationPlanRow::query()
            ->where('session_key', $this->sessionKey)
            ->where('include', true)
            ->whereNotNull('matched_target_channel_id')
            ->select(['source_channel_id', 'matched_target_channel_id', 'epg_channel_id', 'epg_confirmed'])
            ->cursor()
            ->each(function (ProviderMigrationPlanRow $row) use (&$map, $preserveEpg): void {
                $map[] = [
                    'source_id' => (int) $row->source_channel_id,
                    'target_id' => (int) $row->matched_target_channel_id,
                    'epg_channel_id' => $preserveEpg && $row->epg_channel_id ? (int) $row->epg_channel_id : null,
                    'epg_confirmed' => (bool) $row->epg_confirmed,
                ];
            });

        return $map;
    }

    private function applyMatchChoice(ProviderMigrationPlanRow $record, mixed $targetId): void
    {
        $channel = $targetId
            ? $this->scopedTargetChannels()?->clone()->find((int) $targetId)
            : null;

        // A target channel can only receive one source lineup entry (the auto-resolver
        // enforces this; the manual override has to as well). If another row in this
        // preview already points here, release it so counts and writes stay one-to-one.
        if ($channel) {
            $displaced = ProviderMigrationPlanRow::query()
                ->where('session_key', $this->sessionKey)
                ->where('matched_target_channel_id', $channel->id)
                ->whereKeyNot($record->id)
                ->get();

            if ($displaced->isNotEmpty()) {
                ProviderMigrationPlanRow::query()
                    ->whereKey($displaced->pluck('id'))
                    ->update([
                        'matched_target_channel_id' => null,
                        'matched_target_name' => null,
                        'matched_target_stream_id' => null,
                        'bucket' => 'unmatched',
                        'include' => false,
                        // No target, so any EPG proposal on these rows is moot.
                        'epg_channel_id' => null,
                        'epg_status' => 'none',
                        'epg_confirmed' => false,
                    ]);

                Notification::make()
                    ->warning()
                    ->title(__('Match reassigned'))
                    ->body(trans_choice(
                        '{1}:name was already pointing at that replacement channel and has been unset.|[2,*]:count source channels were pointing at that replacement channel and have been unset.',
                        $displaced->count(),
                        ['name' => $displaced->first()->source_name, 'count' => $displaced->count()],
                    ))
                    ->send();
            }
        }

        $record->update([
            'matched_target_channel_id' => $channel?->id,
            'matched_target_name' => $channel?->name,
            'matched_target_stream_id' => $channel?->stream_id,
            'bucket' => $channel ? 'matched' : ($record->bucket === 'matched' ? 'unmatched' : $record->bucket),
            'include' => $channel ? true : $record->include,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function searchTargetChannels(string $search): array
    {
        $query = $this->scopedTargetChannels();
        if (! $query) {
            return [];
        }

        return $query
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $qq) => $qq
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_custom', 'like', "%{$search}%")
                ->orWhere('stream_id', 'like', "%{$search}%")))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'name_custom', 'stream_id', 'stream_id_custom'])
            ->mapWithKeys(fn (Channel $c): array => [
                $c->id => trim(($c->name_custom ?: $c->name).' ('.($c->stream_id_custom ?: $c->stream_id).')'),
            ])
            ->all();
    }

    private function targetChannelLabel(int $channelId): ?string
    {
        $channel = $this->scopedTargetChannels()
            ?->clone()
            ->find($channelId, ['id', 'name', 'name_custom', 'stream_id', 'stream_id_custom']);
        if (! $channel) {
            return null;
        }

        return trim(($channel->name_custom ?: $channel->name).' ('.($channel->stream_id_custom ?: $channel->stream_id).')');
    }

    private function candidateLabels(ProviderMigrationPlanRow $record): string
    {
        $ids = $record->candidate_target_channel_ids ?? [];
        $query = $this->scopedTargetChannels();
        if (empty($ids) || ! $query) {
            return '-';
        }

        return $query
            ->whereIn('id', $ids)
            ->pluck('name')
            ->implode(', ');
    }

    /**
     * A fresh Channel query restricted to the Live channels of the chosen replacement playlist,
     * but only when that playlist is actually owned by the current user. `target_playlist_id`
     * lives in client-settable form state, so every read of a target channel goes through here
     * rather than trusting the id directly.
     */
    private function scopedTargetChannels(): ?Builder
    {
        $target = Playlist::query()
            ->where('user_id', auth()->id())
            ->where('is_network_playlist', false)
            ->find($this->data['target_playlist_id'] ?? null);

        if (! $target instanceof Playlist) {
            return null;
        }

        return Channel::query()
            ->where('playlist_id', $target->id)
            ->where('is_vod', false);
    }

    private function resolveTarget(): Playlist
    {
        $target = Playlist::query()
            ->where('user_id', auth()->id())
            ->find($this->data['target_playlist_id'] ?? null);

        abort_unless($target instanceof Playlist, 404);

        return $target;
    }
}
