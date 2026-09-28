<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\EpgChannel;
use App\Models\Group;
use App\Models\Playlist;
use App\Services\ProviderMigrationPlanner;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CopyAttributesToPlaylist implements ShouldQueue
{
    use Queueable;

    /**
     * Detailed outcome of the last run, surfaced in the completion notification and readable
     * by the dispatcher (used by the provider migration preview for its dry-run summary).
     *
     * @var array<string, int>
     */
    public array $report = [];

    /** Count of match keys that collided during a legacy copy (logged as a warning). */
    private int $duplicateMatchKeyCount = 0;

    /**
     * Create a new job instance.
     *
     * @param  array<int, string>  $channelAttributes
     * @param  array<int, string>  $channelMatchAttributes
     * @param  array<int, array{source_id: int, target_id: int, epg_channel_id?: int|null, epg_confirmed?: bool}>|null  $resolvedMap
     *                                                                                                                                Explicit source->target mapping from the interactive
     *                                                                                                                                migration UI. Only used when $migrationMode is true;
     *                                                                                                                                when null the planner computes the mapping.
     */
    public function __construct(
        public Playlist $source,
        public int $targetId,
        public array $channelAttributes,
        public array $channelMatchAttributes,
        public bool $createIfMissing = false,
        public bool $allAttributes = false,
        public bool $overwrite = false,
        public bool $migrationMode = false,
        public bool $preserveEpg = false,
        public bool $disableTargetOnly = false,
        public bool $migrateCustomPlaylists = true,
        public ?array $resolvedMap = null,
        public ?string $planFingerprint = null,
        public bool $dryRun = false,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): int
    {
        $sourcePlaylist = $this->source;
        $playlist = Playlist::find($this->targetId);

        // Make sure we still have both playlists
        if (! ($sourcePlaylist && $playlist)) {
            return 0;
        }

        // Provider migration mode revalidates the preview against current data before writing.
        if ($this->migrationMode && $this->planFingerprint !== null) {
            $currentFingerprint = app(ProviderMigrationPlanner::class)->fingerprint($sourcePlaylist, $playlist);
            if (! hash_equals($this->planFingerprint, $currentFingerprint)) {
                Notification::make()
                    ->danger()
                    ->title('Migration preview is out of date')
                    ->body("The channels on \"{$sourcePlaylist->name}\" or \"{$playlist->name}\" changed since the preview was generated. Please re-open the migration preview and try again.")
                    ->broadcast($sourcePlaylist->user)
                    ->sendToDatabase($sourcePlaylist->user);

                return 0;
            }
        }

        try {
            $results = $this->migrationMode
                ? $this->migrateProviderChannels()
                : $this->copyChannelAttributes();
        } catch (\Exception $e) {
            // Log the error
            Log::error('Error copying attributes to playlist: '.$e->getMessage());

            // Notify the user of the failure
            Notification::make()
                ->danger()
                ->title('Error copying playlist settings')
                ->body("There was an error copying the \"{$sourcePlaylist->name}\" settings to \"{$playlist->name}\". Please try again.")
                ->broadcast($sourcePlaylist->user)
                ->sendToDatabase($sourcePlaylist->user);

            return 0;
        }

        // Dry runs never write and never notify; the caller reads $this->report directly.
        if ($this->dryRun) {
            return $results;
        }

        // If here, success! Notify the user
        $body = $this->migrationMode
            ? "\"{$sourcePlaylist->name}\" configuration migrated onto \"{$playlist->name}\". ".$this->summarizeReport()
            : "\"{$sourcePlaylist->name}\" settings have been copied successfully. {$results} channels updated on target \"{$playlist->name}\".";

        Notification::make()
            ->success()
            ->title($this->migrationMode ? 'Provider migration complete' : 'Playlist settings copied')
            ->body($body)
            ->broadcast($sourcePlaylist->user)
            ->sendToDatabase($sourcePlaylist->user);

        return $results;
    }

    /**
     * Copy channel attributes from source playlist to target playlist
     */
    private function copyChannelAttributes(): int
    {
        $sourcePlaylist = $this->source;
        $targetPlaylist = Playlist::find($this->targetId);

        // Get the attribute mapping for copying
        $attributeMapping = $this->getAttributeMapping();

        // Build the source fields to select - include both base and custom fields
        $sourceFieldsToSelect = [
            'id',
            'source_id',
            'is_vod',
            'name',
            'name_custom',
            'title',
            'title_custom',
            'stream_id',
            'stream_id_custom',
            'logo_internal',
            'enabled',
            'group',
            'channel',
            'shift',
            'station_id',
            'url',
        ];

        // Add any additional fields from attribute mapping
        foreach ($attributeMapping as $sourceField => $targetFieldOrFields) {
            if (is_array($targetFieldOrFields)) {
                $sourceFieldsToSelect = array_merge($sourceFieldsToSelect, $targetFieldOrFields);
            } else {
                $sourceFieldsToSelect[] = $sourceField;
            }
        }

        // Add match attributes to ensure they're available for matching
        $sourceFieldsToSelect = array_merge($sourceFieldsToSelect, $this->channelMatchAttributes);
        $sourceFieldsToSelect = array_unique($sourceFieldsToSelect);

        $totalUpdated = 0;
        $totalCreated = 0;
        $batchSize = 1000;

        // Preload existing groups for the target playlist into a case-insensitive map
        $groupNameToId = [];
        $groupNameToTargetId = []; // track existing target group IDs for sort_order updates
        foreach ($targetPlaylist->groups()->get(['id', 'name']) as $g) {
            $groupNameToId[strtolower($g->name ?? '')] = $g->id;
            $groupNameToTargetId[strtolower($g->name ?? '')] = $g->id;
        }

        // Preload source group sort orders by name
        $sourceGroupSortOrders = [];
        foreach ($sourcePlaylist->groups()->get(['name', 'sort_order']) as $g) {
            $sourceGroupSortOrders[strtolower($g->name ?? '')] = $g->sort_order ?? 0;
        }

        // Build the target fields to select for matching
        $targetFieldsToSelect = array_unique(array_merge(['id'], $this->channelMatchAttributes));

        // If we're creating missing channels, we process from source → target
        // Otherwise, we process from target → source (for updates only)
        if ($this->createIfMissing) {
            // Process source channels in chunks, creating or updating as needed
            $sourcePlaylist->channels()
                ->select($sourceFieldsToSelect)
                ->chunkById($batchSize, function ($sourceChannels) use ($targetPlaylist, $targetFieldsToSelect, $attributeMapping, &$totalUpdated, &$totalCreated, &$groupNameToId, $sourceGroupSortOrders) {
                    $updates = [];
                    $channelsToCreate = [];

                    // Build WHERE conditions to find matching target channels for this source chunk
                    $matchConditions = $this->buildMatchConditions($sourceChannels, $this->channelMatchAttributes);

                    if (empty($matchConditions)) {
                        return; // No valid match conditions for this chunk
                    }

                    // Query only the target channels that could potentially match this source chunk
                    $targetChannelsQuery = $targetPlaylist->channels()->select($targetFieldsToSelect);
                    $this->applyMatchConditions($targetChannelsQuery, $matchConditions);

                    // Build lookup of existing target channels by match key
                    $targetChannelsByMatchKey = [];
                    foreach ($targetChannelsQuery->cursor() as $targetChannel) {
                        $matchKey = $this->buildMatchKey($targetChannel, $this->channelMatchAttributes);
                        if ($matchKey !== null) {
                            if (isset($targetChannelsByMatchKey[$matchKey])) {
                                $this->duplicateMatchKeyCount++;
                            }
                            $targetChannelsByMatchKey[$matchKey] = $targetChannel;
                        }
                    }

                    // Process each source channel
                    foreach ($sourceChannels as $sourceChannel) {
                        $matchKey = $this->buildMatchKey($sourceChannel, $this->channelMatchAttributes);

                        if ($matchKey === null) {
                            continue; // Can't match without a valid key
                        }

                        // Check if target channel exists
                        if (isset($targetChannelsByMatchKey[$matchKey])) {
                            // Update existing channel
                            $targetChannel = $targetChannelsByMatchKey[$matchKey];
                            $updateData = $this->buildUpdateData($sourceChannel, $targetChannel, $attributeMapping, $groupNameToId, $targetPlaylist, $sourceGroupSortOrders);

                            if (! empty($updateData)) {
                                $updateData['updated_at'] = now();
                                $updates[$targetChannel->id] = $updateData;
                            }
                        } else {
                            // Create new channel
                            $channelData = $this->buildChannelData($sourceChannel, $targetPlaylist, $groupNameToId, $sourceGroupSortOrders);
                            $channelsToCreate[] = $channelData;
                        }
                    }

                    // Persist this chunk atomically so a mid-run failure cannot leave the
                    // target playlist half-updated.
                    DB::transaction(function () use ($updates, $channelsToCreate, &$totalUpdated, &$totalCreated) {
                        foreach ($updates as $channelId => $updateData) {
                            Channel::query()->where('id', $channelId)->update($updateData);
                            $totalUpdated++;
                        }

                        // Insert new channels using Eloquent to ensure casts are applied.
                        foreach ($channelsToCreate as $channelData) {
                            Channel::create($channelData);
                        }
                        $totalCreated += count($channelsToCreate);
                    });
                });
        } else {
            // Process target channels in chunks, updating only (no creation)
            $targetPlaylist->channels()
                ->select(array_unique(array_merge($targetFieldsToSelect, [
                    'id',
                    'name_custom',
                    'title_custom',
                    'stream_id_custom',
                    'logo',
                    'enabled',
                    'group',
                    'group_id',
                    'shift',
                    'channel',
                    'station_id',
                    'sort',
                ])))
                ->chunkById($batchSize, function ($targetChannels) use ($sourcePlaylist, $sourceFieldsToSelect, $attributeMapping, &$totalUpdated, &$groupNameToId, $targetPlaylist, $sourceGroupSortOrders) {
                    $updates = [];

                    // Build WHERE conditions to find matching source channels
                    $matchConditions = $this->buildMatchConditions($targetChannels, $this->channelMatchAttributes);

                    if (empty($matchConditions)) {
                        return;
                    }

                    // Query only the source channels that could potentially match this target chunk
                    $sourceChannelsQuery = $sourcePlaylist->channels()->select($sourceFieldsToSelect);
                    $this->applyMatchConditions($sourceChannelsQuery, $matchConditions);

                    // Build lookup of source channels by match key
                    $sourceChannelsByMatchKey = [];
                    foreach ($sourceChannelsQuery->cursor() as $sourceChannel) {
                        $matchKey = $this->buildMatchKey($sourceChannel, $this->channelMatchAttributes);
                        if ($matchKey !== null) {
                            if (isset($sourceChannelsByMatchKey[$matchKey])) {
                                $this->duplicateMatchKeyCount++;
                            }
                            $sourceChannelsByMatchKey[$matchKey] = $sourceChannel;
                        }
                    }

                    // Process each target channel
                    foreach ($targetChannels as $targetChannel) {
                        $matchKey = $this->buildMatchKey($targetChannel, $this->channelMatchAttributes);

                        if ($matchKey === null || ! isset($sourceChannelsByMatchKey[$matchKey])) {
                            continue;
                        }

                        $sourceChannel = $sourceChannelsByMatchKey[$matchKey];
                        $updateData = $this->buildUpdateData($sourceChannel, $targetChannel, $attributeMapping, $groupNameToId, $targetPlaylist, $sourceGroupSortOrders);

                        if (! empty($updateData)) {
                            $updateData['updated_at'] = now();
                            $updates[$targetChannel->id] = $updateData;
                        }
                    }

                    // Persist this chunk atomically.
                    if (! empty($updates)) {
                        DB::transaction(function () use ($updates, &$totalUpdated) {
                            foreach ($updates as $channelId => $updateData) {
                                DB::table('channels')
                                    ->where('id', $channelId)
                                    ->update($updateData);
                                $totalUpdated++;
                            }
                        });
                    }
                });
        }

        if ($this->duplicateMatchKeyCount > 0) {
            Log::warning("CopyAttributesToPlaylist: {$this->duplicateMatchKeyCount} duplicate match keys were collapsed (last row wins) while copying from playlist {$sourcePlaylist->id} to playlist {$targetPlaylist->id}. Consider narrowing the match attributes.");
        }

        $totalProcessed = $totalUpdated + $totalCreated;
        Log::info("CopyAttributesToPlaylist: Updated {$totalUpdated} and created {$totalCreated} channels from playlist {$sourcePlaylist->id} to playlist {$targetPlaylist->id}");

        return $totalProcessed;
    }

    /**
     * The only channel columns that may be used to match rows between playlists. Any attribute
     * outside this allowlist is ignored, so no caller-supplied value ever reaches raw SQL.
     */
    private const MATCHABLE_COLUMNS = [
        'name', 'title', 'url', 'stream_id', 'stream_id_custom',
        'station_id', 'logo_internal', 'source_id', 'channel',
    ];

    /**
     * Apply the widening prefilter for a match-condition set. String columns are wrapped in
     * LOWER(TRIM(...)) so the SQL prefilter agrees with the normalized PHP match key on
     * case-sensitive collations (Postgres); the integer channel-number column is compared raw.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|Builder  $query
     * @param  array<string, array<int, string>>  $matchConditions
     */
    private function applyMatchConditions($query, array $matchConditions): void
    {
        foreach ($this->channelMatchAttributes as $attribute) {
            // Whitelist guard: the column identifier below is interpolated into raw SQL, so it
            // must be one of a fixed set of known-safe column names, never an arbitrary string.
            if (! in_array($attribute, self::MATCHABLE_COLUMNS, true) || empty($matchConditions[$attribute])) {
                continue;
            }

            if ($attribute === 'channel') {
                $query->whereIn($attribute, $matchConditions[$attribute]);

                continue;
            }

            $query->whereIn(
                DB::raw('LOWER(TRIM('.$query->getGrammar()->wrap('channels.'.$attribute).'))'),
                $matchConditions[$attribute],
            );
        }
    }

    /**
     * Provider migration mode: copy only the reviewed presentation/EPG configuration from the
     * (expired) source playlist onto its matched channels in the (working) target playlist.
     * The target keeps its own URLs, provider IDs, credentials and import identity.
     */
    private function migrateProviderChannels(): int
    {
        $sourcePlaylist = $this->source;
        $targetPlaylist = Playlist::find($this->targetId);

        $attributeMapping = $this->getAttributeMapping();
        $pairs = $this->resolveMigrationPairs($sourcePlaylist, $targetPlaylist);

        $report = [
            'updated' => 0,
            'skipped' => 0,
            'epg_copied' => 0,
            'epg_skipped' => 0,
            'epg_conflicts' => 0,
            'disabled' => 0,
            'unmatched_source' => 0,
            'matched' => count($pairs),
            'custom_playlist_rows_repointed' => 0,
            'custom_playlist_rows_dropped' => 0,
        ];

        if (empty($pairs)) {
            $this->report = $report;

            return 0;
        }

        $sourceIds = array_column($pairs, 'source_id');
        $targetIds = array_column($pairs, 'target_id');

        // The whole build+write runs in one transaction so target groups auto-created while
        // resolving names are covered by the same atomic unit; a dry run rolls it all back.
        $rollbackForDryRun = new class extends \RuntimeException {};

        try {
            DB::transaction(function () use ($sourcePlaylist, $targetPlaylist, $pairs, $sourceIds, $targetIds, $attributeMapping, &$report, $rollbackForDryRun) {
                $groupNameToId = [];
                foreach ($targetPlaylist->groups()->get(['id', 'name']) as $g) {
                    $groupNameToId[strtolower($g->name ?? '')] = $g->id;
                }
                $sourceGroupSortOrders = [];
                foreach ($sourcePlaylist->groups()->get(['name', 'sort_order']) as $g) {
                    $sourceGroupSortOrders[strtolower($g->name ?? '')] = $g->sort_order ?? 0;
                }

                $sourceById = $sourcePlaylist->channels()
                    ->whereIn('id', $sourceIds)
                    ->get([
                        'id', 'name', 'name_custom', 'title', 'title_custom', 'stream_id',
                        'stream_id_custom', 'logo_internal', 'enabled', 'group', 'channel',
                        'shift', 'sort', 'station_id', 'epg_channel_id',
                    ])
                    ->keyBy('id');

                $targetById = $targetPlaylist->channels()
                    ->whereIn('id', $targetIds)
                    ->get([
                        'id', 'name_custom', 'title_custom', 'stream_id_custom', 'logo', 'enabled',
                        'group', 'group_id', 'shift', 'channel', 'station_id', 'sort', 'epg_channel_id',
                    ])
                    ->keyBy('id');

                $updates = [];
                foreach ($pairs as $pair) {
                    $sourceChannel = $sourceById->get($pair['source_id']);
                    $targetChannel = $targetById->get($pair['target_id']);
                    if (! $sourceChannel || ! $targetChannel) {
                        $report['skipped']++;

                        continue;
                    }

                    $updateData = $this->buildUpdateData(
                        $sourceChannel,
                        $targetChannel,
                        $attributeMapping,
                        $groupNameToId,
                        $targetPlaylist,
                        $sourceGroupSortOrders,
                    );

                    if ($this->preserveEpg) {
                        $updateData = $this->applyEpgProposal($updateData, $targetChannel, $pair, $report);
                    }

                    if (empty($updateData)) {
                        $report['skipped']++;

                        continue;
                    }

                    $updateData['updated_at'] = now();
                    $updates[$targetChannel->id] = $updateData;
                }

                foreach ($updates as $channelId => $updateData) {
                    Channel::query()->where('id', $channelId)->update($updateData);
                }
                $report['updated'] = count($updates);

                // Optionally disable target-only Live channels for a curated-lineup migration.
                if ($this->disableTargetOnly) {
                    $toDisable = $targetPlaylist->channels()
                        ->where('is_vod', false)
                        ->where('enabled', true)
                        ->whereNotIn('id', $targetIds)
                        ->pluck('id')
                        ->all();
                    if (! empty($toDisable)) {
                        Channel::query()->whereIn('id', $toDisable)->update(['enabled' => false, 'updated_at' => now()]);
                    }
                    $report['disabled'] = count($toDisable);
                }

                // Repoint Custom Playlist membership: any custom playlist that already curated
                // channels from the (expired) source playlist should keep pointing at the same
                // logical channel, now living on the replacement provider.
                if ($this->migrateCustomPlaylists) {
                    $this->repointCustomPlaylistMemberships($pairs, $report);
                }

                if ($this->dryRun) {
                    throw $rollbackForDryRun;
                }
            });
        } catch (\RuntimeException $e) {
            if ($e !== $rollbackForDryRun) {
                throw $e;
            }
        }

        $this->report = $report;

        if (! $this->dryRun) {
            Log::info("CopyAttributesToPlaylist[migration]: {$report['updated']} channels migrated from playlist {$sourcePlaylist->id} onto playlist {$targetPlaylist->id} (epg copied {$report['epg_copied']}, disabled {$report['disabled']}, custom playlist rows repointed {$report['custom_playlist_rows_repointed']}).");
        }

        return $report['updated'];
    }

    /**
     * Normalize the source->target mapping for migration mode. When the interactive UI supplies
     * an explicit $resolvedMap it is authoritative; otherwise the planner computes it.
     *
     * @return array<int, array{source_id: int, target_id: int, epg_channel_id: int|null, epg_confirmed: bool}>
     */
    private function resolveMigrationPairs(Playlist $sourcePlaylist, Playlist $targetPlaylist): array
    {
        if (is_array($this->resolvedMap)) {
            $pairs = [];
            foreach ($this->resolvedMap as $entry) {
                if (empty($entry['source_id']) || empty($entry['target_id'])) {
                    continue;
                }
                $pairs[] = [
                    'source_id' => (int) $entry['source_id'],
                    'target_id' => (int) $entry['target_id'],
                    'epg_channel_id' => isset($entry['epg_channel_id']) && $entry['epg_channel_id'] !== null
                        ? (int) $entry['epg_channel_id']
                        : null,
                    'epg_confirmed' => (bool) ($entry['epg_confirmed'] ?? false),
                ];
            }

            return $pairs;
        }

        $plan = app(ProviderMigrationPlanner::class)->plan($sourcePlaylist, $targetPlaylist->id, [
            'preserve_epg' => $this->preserveEpg,
        ]);

        $pairs = [];
        foreach ($plan['matched'] as $row) {
            $epg = $row['epg'] ?? null;
            $pairs[] = [
                'source_id' => (int) $row['source']['id'],
                'target_id' => (int) $row['target']['id'],
                'epg_channel_id' => $epg && in_array($epg['status'], ['ok', 'conflict'], true)
                    ? ($epg['proposed_epg_channel_id'] ?? null)
                    : null,
                // Auto (non-interactive) migrations never silently replace an existing mapping.
                'epg_confirmed' => false,
            ];
        }

        return $pairs;
    }

    /**
     * Decide whether the reviewed EPG mapping should be written onto a matched target channel.
     *
     * @param  array<string, mixed>  $updateData
     * @param  array{epg_channel_id: int|null, epg_confirmed: bool}  $pair
     * @param  array<string, int>  $report
     * @return array<string, mixed>
     */
    private function applyEpgProposal(array $updateData, Channel $targetChannel, array $pair, array &$report): array
    {
        $proposedId = $pair['epg_channel_id'];
        if ($proposedId === null) {
            $report['epg_skipped']++;

            return $updateData;
        }

        $currentId = $targetChannel->epg_channel_id ? (int) $targetChannel->epg_channel_id : null;

        if ($currentId === $proposedId) {
            return $updateData; // Already mapped as desired; nothing to do.
        }

        // Replacing a different existing mapping requires explicit confirmation.
        if ($currentId !== null && ! $pair['epg_confirmed']) {
            $report['epg_conflicts']++;
            $report['epg_skipped']++;

            return $updateData;
        }

        // Guard against a dangling FK.
        if (! EpgChannel::query()->whereKey($proposedId)->exists()) {
            $report['epg_skipped']++;

            return $updateData;
        }

        $updateData['epg_channel_id'] = $proposedId;
        $report['epg_copied']++;

        return $updateData;
    }

    /**
     * Human-readable one-liner of the migration report for the completion notification.
     */
    private function summarizeReport(): string
    {
        $r = $this->report;

        return trim(sprintf(
            '%d channels updated, %d skipped, %d EPG mappings copied%s%s%s.',
            $r['updated'] ?? 0,
            $r['skipped'] ?? 0,
            $r['epg_copied'] ?? 0,
            ($r['epg_conflicts'] ?? 0) > 0 ? sprintf(', %d EPG conflicts left unchanged', $r['epg_conflicts']) : '',
            ($r['disabled'] ?? 0) > 0 ? sprintf(', %d target-only channels disabled', $r['disabled']) : '',
            ($r['custom_playlist_rows_repointed'] ?? 0) > 0 ? sprintf(', %d Custom Playlist entries repointed', $r['custom_playlist_rows_repointed']) : '',
        ));
    }

    /**
     * Repoint Custom Playlist channel membership from the (expired) source channel onto its
     * matched replacement channel, so a Custom Playlist that curated channels from this
     * playlist keeps serving them, now via the replacement provider. Per-custom-playlist
     * channel number/sort order and the custom group tag travel with the repointed row.
     *
     * If the replacement channel is already independently a member of the same Custom Playlist
     * (a collision on the pivot's unique (channel_id, custom_playlist_id) pair), that existing
     * membership is left untouched and the now-redundant source row is simply dropped.
     *
     * @param  array<int, array{source_id: int, target_id: int}>  $pairs
     * @param  array<string, int>  $report
     */
    private function repointCustomPlaylistMemberships(array $pairs, array &$report): void
    {
        $sourceToTarget = [];
        foreach ($pairs as $pair) {
            $sourceToTarget[$pair['source_id']] = $pair['target_id'];
        }

        if (empty($sourceToTarget)) {
            return;
        }

        $rows = DB::table('channel_custom_playlist')
            ->whereIn('channel_id', array_keys($sourceToTarget))
            ->get(['channel_id', 'custom_playlist_id']);

        if ($rows->isEmpty()) {
            return;
        }

        $existingTargetMemberships = DB::table('channel_custom_playlist')
            ->whereIn('channel_id', array_values($sourceToTarget))
            ->get(['channel_id', 'custom_playlist_id'])
            ->map(fn ($row): string => $row->channel_id.':'.$row->custom_playlist_id)
            ->flip();

        $repointed = 0;
        $dropped = 0;
        $tagCandidates = [];

        foreach ($rows as $row) {
            $targetChannelId = $sourceToTarget[$row->channel_id];
            $collisionKey = $targetChannelId.':'.$row->custom_playlist_id;

            if (isset($existingTargetMemberships[$collisionKey])) {
                // The replacement channel is already independently in this Custom Playlist;
                // keep that membership and discard the now-dead source-side row.
                DB::table('channel_custom_playlist')
                    ->where('channel_id', $row->channel_id)
                    ->where('custom_playlist_id', $row->custom_playlist_id)
                    ->delete();
                $dropped++;

                continue;
            }

            DB::table('channel_custom_playlist')
                ->where('channel_id', $row->channel_id)
                ->where('custom_playlist_id', $row->custom_playlist_id)
                ->update(['channel_id' => $targetChannelId]);
            $repointed++;

            $tagCandidates[] = [
                'source_channel_id' => $row->channel_id,
                'target_channel_id' => $targetChannelId,
                'custom_playlist_id' => $row->custom_playlist_id,
            ];
        }

        $this->moveCustomPlaylistGroupTags($tagCandidates);

        $report['custom_playlist_rows_repointed'] = $repointed;
        $report['custom_playlist_rows_dropped'] = $dropped;
    }

    /**
     * Re-apply each moved channel's per-custom-playlist "custom group" tag (a Spatie tag scoped
     * to the Custom Playlist's uuid) onto its replacement channel, so the group label survives
     * the repoint.
     *
     * @param  array<int, array{source_channel_id: int, target_channel_id: int, custom_playlist_id: int}>  $candidates
     */
    private function moveCustomPlaylistGroupTags(array $candidates): void
    {
        if (empty($candidates)) {
            return;
        }

        $customPlaylistUuids = CustomPlaylist::query()
            ->whereIn('id', array_unique(array_column($candidates, 'custom_playlist_id')))
            ->pluck('uuid', 'id');

        $sourceChannels = Channel::query()
            ->whereIn('id', array_unique(array_column($candidates, 'source_channel_id')))
            ->with('tags')
            ->get()
            ->keyBy('id');

        $targetChannels = Channel::query()
            ->whereIn('id', array_unique(array_column($candidates, 'target_channel_id')))
            ->get()
            ->keyBy('id');

        foreach ($candidates as $candidate) {
            $uuid = $customPlaylistUuids->get($candidate['custom_playlist_id']);
            $sourceChannel = $sourceChannels->get($candidate['source_channel_id']);
            $targetChannel = $targetChannels->get($candidate['target_channel_id']);
            if (! $uuid || ! $sourceChannel || ! $targetChannel) {
                continue;
            }

            $tag = $sourceChannel->tags->firstWhere('type', $uuid);
            if (! $tag) {
                continue;
            }

            $targetChannel->attachTag($tag->getAttributeValue('name'), $uuid);
        }
    }

    /**
     * Build update data array for an existing target channel from source channel
     */
    private function buildUpdateData(
        $sourceChannel,
        $targetChannel,
        array $attributeMapping,
        array &$groupNameToId,
        Playlist $targetPlaylist,
        array $sourceGroupSortOrders = []
    ): array {
        $updateData = [];

        foreach ($attributeMapping as $sourceField => $targetFieldOrFields) {
            // Handle case where targetFieldOrFields is an array [target_custom, fallback]
            if (is_array($targetFieldOrFields)) {
                $targetField = $targetFieldOrFields[0];
                $sourceValue = null;
                foreach ($targetFieldOrFields as $field) {
                    $sourceValue = $sourceChannel->{$field};
                    if ($sourceValue !== null) {
                        break;
                    }
                }
            } else {
                $targetField = $targetFieldOrFields;
                $sourceValue = $sourceChannel->{$sourceField};
            }

            $targetValue = $targetChannel->{$targetField};

            // Only update if we have a value to copy and either overwrite is enabled
            // or the target field is empty
            if ($sourceValue === null || (! $this->overwrite && $targetValue !== null)) {
                continue;
            }

            // Special handling for group: translate group name into group_id on target
            if ($targetField === 'group') {
                $desiredName = trim((string) $sourceValue);
                if ($desiredName === '') {
                    continue;
                }

                $lower = strtolower($desiredName);

                $sortOrder = $sourceGroupSortOrders[$lower] ?? 0;

                if (array_key_exists($lower, $groupNameToId)) {
                    $groupId = $groupNameToId[$lower];
                    // Update sort_order on the existing target group if overwrite is enabled
                    if ($this->overwrite && isset($sourceGroupSortOrders[$lower])) {
                        Group::query()->where('id', $groupId)->update(['sort_order' => $sortOrder]);
                    }
                } else {
                    // Create the group for the target playlist and cache the id
                    $customGroup = Group::query()->create([
                        'name' => $desiredName,
                        'playlist_id' => $targetPlaylist->id,
                        'user_id' => $targetPlaylist->user_id ?? null,
                        'sort_order' => $sortOrder,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $groupId = $customGroup->id;
                    $groupNameToId[$lower] = $groupId;
                }

                $updateData['group'] = $desiredName;
                $updateData['group_id'] = $groupId;

                continue;
            }

            // Default copy behavior
            $updateData[$targetField] = $sourceValue;
        }

        return $updateData;
    }

    /**
     * Build channel data array for creating a new channel from source
     */
    private function buildChannelData(
        $sourceChannel,
        Playlist $targetPlaylist,
        array &$groupNameToId,
        array $sourceGroupSortOrders = []
    ): array {
        $channelData = [
            'is_custom' => true,
            'playlist_id' => $targetPlaylist->id,
            'user_id' => $targetPlaylist->user_id,
            'is_vod' => $sourceChannel->is_vod ?? false,
            'source_id' => $sourceChannel->source_id ?? null,
            'name' => $sourceChannel->name ?? null,
            'title' => $sourceChannel->title ?? null,
            'url' => $sourceChannel->url ?? null,
            'logo' => $sourceChannel->logo ?? null,
            'logo_internal' => $sourceChannel->logo ?? $sourceChannel->logo_internal ?? null,
            'stream_id' => $sourceChannel->stream_id ?? null,
            'station_id' => $sourceChannel->station_id ?? null,
            'channel' => $sourceChannel->channel ?? null,
            'shift' => $sourceChannel->shift ?? 0,
            'enabled' => $sourceChannel->enabled ?? true,
            'group' => $sourceChannel->group ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Copy custom fields if they exist on the source
        if (isset($sourceChannel->name_custom)) {
            $channelData['name_custom'] = $sourceChannel->name_custom;
        }
        if (isset($sourceChannel->title_custom)) {
            $channelData['title_custom'] = $sourceChannel->title_custom;
        }
        if (isset($sourceChannel->stream_id_custom)) {
            $channelData['stream_id_custom'] = $sourceChannel->stream_id_custom;
        }

        // Handle group creation/assignment
        if (! empty($sourceChannel->group)) {
            $groupName = trim((string) $sourceChannel->group);
            $lower = strtolower($groupName);
            $sortOrder = $sourceGroupSortOrders[$lower] ?? 0;

            if (array_key_exists($lower, $groupNameToId)) {
                $channelData['group_id'] = $groupNameToId[$lower];
            } else {
                // Create the group and cache it
                $customGroup = Group::query()->create([
                    'name' => $groupName,
                    'playlist_id' => $targetPlaylist->id,
                    'user_id' => $targetPlaylist->user_id ?? null,
                    'sort_order' => $sortOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $groupNameToId[$lower] = $customGroup->id;
                $channelData['group_id'] = $customGroup->id;
            }
        }

        return $channelData;
    }

    /**
     * Build WHERE conditions for efficiently querying matching source channels
     * Returns an array mapping each match attribute to the set of values from target channels
     *
     * @param  Collection  $targetChannels
     * @return array<string, array<string>> Map of attribute => unique values
     */
    private function buildMatchConditions($targetChannels, array $matchAttributes): array
    {
        $conditions = [];

        foreach ($matchAttributes as $attribute) {
            $values = [];
            foreach ($targetChannels as $channel) {
                $value = $channel->{$attribute} ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                // Normalize to match buildMatchKey() and the LOWER(TRIM(...)) SQL prefilter.
                // The integer channel-number column is left as-is.
                $values[] = $attribute === 'channel'
                    ? $value
                    : strtolower(trim((string) $value));
            }

            // Store unique values for this attribute
            if (! empty($values)) {
                $conditions[$attribute] = array_values(array_unique($values));
            }
        }

        return $conditions;
    }

    /**
     * Build a composite match key from the channel using the specified match attributes
     *
     * @param  Channel  $channel
     * @return string|null Returns null if any required match attribute is empty
     */
    private function buildMatchKey($channel, array $matchAttributes): ?string
    {
        $keyParts = [];

        foreach ($matchAttributes as $attribute) {
            $value = $channel->{$attribute} ?? null;

            // If any match attribute is null/empty, we can't create a valid match key
            if ($value === null || $value === '') {
                return null;
            }

            // Normalize the value for consistent matching
            $keyParts[] = strtolower(trim((string) $value));
        }

        // Create a composite key by joining all parts with a delimiter
        return implode('|', $keyParts);
    }

    /**
     * Get the mapping of source fields to target custom fields
     */
    private function getAttributeMapping(): array
    {
        $mapping = [];

        // If copying all attributes, include all supported attributes
        if ($this->allAttributes) {
            return [
                'logo_internal' => 'logo',  // Special case: logo_internal (source) -> logo (custom override)
                'name' => ['name_custom', 'name'], // Prefer custom, fallback to base
                'title' => ['title_custom', 'title'], // Prefer custom, fallback to base
                'stream_id' => ['stream_id_custom', 'stream_id'], // Prefer custom, fallback to base
                'station_id' => 'station_id',
                'enabled' => 'enabled',
                'group' => 'group',
                'shift' => 'shift',
                'channel' => 'channel',
                'sort' => 'sort',
            ];
        }

        // Map selected attributes to their custom field equivalents
        foreach ($this->channelAttributes as $attribute) {
            switch ($attribute) {
                // Handle special cases first. Accept both the UI option key ('logo_internal')
                // and the shorthand ('logo'); both mean "copy the source imported logo into the
                // target custom logo override".
                case 'logo':
                case 'logo_internal':
                    $mapping['logo_internal'] = 'logo';
                    break;

                    // Then custom field mappings
                case 'name':
                    $mapping['name'] = ['name_custom', 'name']; // Prefer custom, fallback to base
                    break;
                case 'title':
                    $mapping['title'] = ['title_custom', 'title']; // Prefer custom, fallback to base
                    break;
                case 'stream_id':
                    $mapping['stream_id'] = ['stream_id_custom', 'stream_id']; // Prefer custom, fallback to base
                    break;

                    // And finally, direct mappings without custom fields
                case 'enabled':
                case 'station_id':
                case 'group':
                case 'shift':
                case 'channel':
                case 'sort':
                    $mapping[$attribute] = $attribute;
                    break;
            }
        }

        return $mapping;
    }
}
