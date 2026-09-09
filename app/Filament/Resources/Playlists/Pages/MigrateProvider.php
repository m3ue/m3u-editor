<?php

namespace App\Filament\Resources\Playlists\Pages;

use App\Filament\Resources\Playlists\PlaylistResource;
use App\Jobs\CopyAttributesToPlaylist;
use App\Models\Playlist;
use App\Services\ChannelMatchResolver;
use App\Services\ProviderMigrationPlanner;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Interactive "provider migration": preview how an expired playlist's curated Live TV lineup
 * maps onto a working replacement playlist, resolve ambiguous / unmatched channels by hand,
 * optionally preserve EPG mappings, then apply the reviewed plan onto the working playlist.
 */
class MigrateProvider extends Page
{
    use InteractsWithRecord;

    protected static string $resource = PlaylistResource::class;

    protected string $view = 'filament.resources.playlists.pages.migrate-provider';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** The most recent plan fingerprint, revalidated server-side on apply. */
    public ?string $planFingerprint = null;

    /** Cached "target Live channel id => label" options for the review selects. */
    public array $targetChannelOptions = [];

    /** Summary counts from the last dry run, rendered on the final step. */
    public array $dryRunReport = [];

    /** Ambiguous / unmatched context lines for the review step. */
    public array $planNotes = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();
        abort_unless($this->record->user_id === auth()->id(), 403);
        abort_if($this->record->is_network_playlist || $this->record->isMediaServerPlaylist(), 404);

        $this->form->fill([
            'target_playlist_id' => null,
            'passes' => ChannelMatchResolver::DEFAULT_PASSES,
            'fields' => ['enabled', 'group', 'sort', 'channel'],
            'preserve_epg' => true,
            'disable_target_only' => false,
            'overwrite' => true,
            'mappings' => [],
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
                Wizard::make([
                    Step::make(__('Configure'))
                        ->icon('heroicon-o-adjustments-horizontal')
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
                                ->helperText(__('When off, only empty fields on the replacement channels are filled.'))
                                ->default(true),
                            Toggle::make('disable_target_only')
                                ->label(__('Disable channels that are not in this lineup'))
                                ->helperText(__('Turns off replacement channels with no match here, for a strict curated-lineup migration. Nothing is deleted.'))
                                ->default(false),
                        ])
                        ->afterValidation(function (): void {
                            $this->buildPreview();
                        }),

                    Step::make(__('Review matches'))
                        ->icon('heroicon-o-list-bullet')
                        ->schema([
                            Callout::make()
                                ->visible(fn (): bool => ! empty($this->planNotes))
                                ->warning()
                                ->description(fn (): string => implode(' ', $this->planNotes)),
                            Repeater::make('mappings')
                                ->hiddenLabel()
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->itemLabel(fn (array $state): ?string => $state['source_label'] ?? null)
                                ->schema([
                                    Hidden::make('source_id'),
                                    Hidden::make('source_label'),
                                    Hidden::make('epg_status'),
                                    Hidden::make('epg_channel_id'),
                                    Select::make('target_id')
                                        ->label(__('Replacement channel'))
                                        ->options(fn (): array => $this->targetChannelOptions)
                                        ->searchable()
                                        ->placeholder(__('Not migrated')),
                                    Toggle::make('include')
                                        ->label(__('Include in migration'))
                                        ->default(true),
                                    Checkbox::make('epg_confirm')
                                        ->label(__('Replace the existing EPG mapping on this channel'))
                                        ->visible(fn (Get $get): bool => $get('epg_status') === 'conflict'),
                                ])
                                ->columns(1),
                        ]),

                    Step::make(__('Apply'))
                        ->icon('heroicon-o-rocket-launch')
                        ->schema([
                            Callout::make()
                                ->description(fn (): string => $this->dryRunSummary()),
                            Callout::make()
                                ->info()
                                ->description(__('Existing clients keep the same playlist output, now served from the replacement provider. Custom Playlist membership, per-custom-playlist channel numbers and tag-based groups are not part of this migration.')),
                        ])
                        ->afterValidation(function (): void {
                            $this->runDryRun();
                        }),
                ])
                    ->columnSpanFull()
                    ->submitAction(view('filament.resources.playlists.pages.migrate-provider-submit')),
            ])
            ->statePath('data');
    }

    /**
     * Build the preview plan and hydrate the review repeater. Runs when leaving the config step.
     */
    public function buildPreview(): void
    {
        $target = $this->resolveTarget();

        $plan = app(ProviderMigrationPlanner::class)->plan($this->record, $target->id, [
            'passes' => $this->data['passes'] ?? ChannelMatchResolver::DEFAULT_PASSES,
            'preserve_epg' => (bool) ($this->data['preserve_epg'] ?? false),
        ]);

        $this->planFingerprint = $plan['fingerprint'];

        $this->targetChannelOptions = $target->channels()
            ->where('is_vod', false)
            ->orderBy('name')
            ->get(['id', 'name', 'name_custom', 'stream_id', 'stream_id_custom'])
            ->mapWithKeys(fn ($c): array => [
                $c->id => trim(($c->name_custom ?: $c->name).' ('.($c->stream_id_custom ?: $c->stream_id).')'),
            ])
            ->all();

        $rows = [];
        foreach ($plan['matched'] as $row) {
            $epg = $row['epg'] ?? null;
            $rows[] = [
                'source_id' => $row['source']['id'],
                'source_label' => $this->sourceLabel($row['source'], $row['match_pass']),
                'target_id' => $row['target']['id'],
                'include' => true,
                'epg_status' => $epg['status'] ?? 'none',
                'epg_channel_id' => ($epg && in_array($epg['status'] ?? '', ['ok', 'conflict'], true))
                    ? ($epg['proposed_epg_channel_id'] ?? null)
                    : null,
                'epg_confirm' => false,
            ];
        }
        foreach ($plan['ambiguous'] as $row) {
            $rows[] = [
                'source_id' => $row['source']['id'],
                'source_label' => $this->sourceLabel($row['source'], $row['match_pass']).' - '.__('needs a manual choice'),
                'target_id' => null,
                'include' => true,
                'epg_status' => 'none',
                'epg_channel_id' => null,
                'epg_confirm' => false,
            ];
        }

        $this->data['mappings'] = $rows;

        $this->planNotes = [];
        if (! empty($plan['ambiguous'])) {
            $this->planNotes[] = trans_choice('{1}:count channel matched more than one replacement channel and needs a manual choice.|[2,*]:count channels matched more than one replacement channel and need a manual choice.', count($plan['ambiguous']), ['count' => count($plan['ambiguous'])]);
        }
        if (! empty($plan['unmatched_source'])) {
            $this->planNotes[] = trans_choice('{1}:count channel from this lineup had no match and will be skipped.|[2,*]:count channels from this lineup had no match and will be skipped.', count($plan['unmatched_source']), ['count' => count($plan['unmatched_source'])]);
        }
    }

    /**
     * Recompute the dry-run report shown on the final step.
     */
    public function runDryRun(): void
    {
        $job = new CopyAttributesToPlaylist(
            source: $this->record,
            targetId: $this->resolveTarget()->id,
            channelAttributes: $this->data['fields'] ?? [],
            channelMatchAttributes: [],
            overwrite: (bool) ($this->data['overwrite'] ?? true),
            migrationMode: true,
            preserveEpg: (bool) ($this->data['preserve_epg'] ?? false),
            disableTargetOnly: (bool) ($this->data['disable_target_only'] ?? false),
            resolvedMap: $this->buildResolvedMap(),
            dryRun: true,
        );
        $job->handle();

        $this->dryRunReport = $job->report;
    }

    public function apply(): void
    {
        $this->form->validate();

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
        $preserveEpg = (bool) ($this->data['preserve_epg'] ?? false);
        $map = [];
        foreach ($this->data['mappings'] ?? [] as $row) {
            if (empty($row['include']) || empty($row['target_id']) || empty($row['source_id'])) {
                continue;
            }
            $map[] = [
                'source_id' => (int) $row['source_id'],
                'target_id' => (int) $row['target_id'],
                'epg_channel_id' => $preserveEpg && ! empty($row['epg_channel_id']) ? (int) $row['epg_channel_id'] : null,
                'epg_confirmed' => (bool) ($row['epg_confirm'] ?? false),
            ];
        }

        return $map;
    }

    private function resolveTarget(): Playlist
    {
        $target = Playlist::query()
            ->where('user_id', auth()->id())
            ->find($this->data['target_playlist_id'] ?? null);

        abort_unless($target instanceof Playlist, 404);

        return $target;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function sourceLabel(array $source, string $pass): string
    {
        return sprintf('%s (%s) - matched by %s', $source['name'] ?? '?', $source['stream_id'] ?? '?', str_replace('_', ' ', $pass));
    }

    private function dryRunSummary(): string
    {
        $r = $this->dryRunReport;
        if (empty($r)) {
            return __('Advance through the review step to calculate the migration summary.');
        }

        return __(':updated channels will be updated, :skipped skipped, :epg EPG mappings copied, :disabled replacement-only channels disabled.', [
            'updated' => $r['updated'] ?? 0,
            'skipped' => $r['skipped'] ?? 0,
            'epg' => $r['epg_copied'] ?? 0,
            'disabled' => $r['disabled'] ?? 0,
        ]);
    }
}
