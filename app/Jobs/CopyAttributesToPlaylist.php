<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CopyAttributesToPlaylist implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $source,
        public int $targetId,
        public array $channelAttributes,
        public array $channelMatchAttributes,
        public bool $createIfMissing = false,
        public bool $allAttributes = false,
        public bool $overwrite = false,
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

        try {
            $results = $this->copyChannelAttributes();
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

        // If here, success! Notify the user
        Notification::make()
            ->success()
            ->title('Playlist settings copied')
            ->body("\"{$sourcePlaylist->name}\" settings have been copied successfully. {$results} channels updated on target \"{$playlist->name}\".")
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
        foreach ($targetPlaylist->groups()->get(['id', 'name']) as $g) {
            $groupNameToId[strtolower($g->name ?? '')] = $g->id;
        }

        // Build the target fields to select for matching
        $targetFieldsToSelect = array_unique(array_merge(['id'], $this->channelMatchAttributes));

        // If we're creating missing channels, we process from source → target
        // Otherwise, we process from target → source (for updates only)
        if ($this->createIfMissing) {
            // Process source channels in chunks, creating or updating as needed
            $sourcePlaylist->channels()
                ->select($sourceFieldsToSelect)
                ->chunkById($batchSize, function ($sourceChannels) use ($targetPlaylist, $targetFieldsToSelect, $attributeMapping, &$totalUpdated, &$totalCreated, &$groupNameToId) {
                    $updates = [];
                    $channelsToCreate = [];

                    // Build WHERE conditions to find matching target channels for this source chunk
                    $matchConditions = $this->buildMatchConditions($sourceChannels, $this->channelMatchAttributes);

                    if (empty($matchConditions)) {
                        return; // No valid match conditions for this chunk
                    }

                    // Query only the target channels that could potentially match this source chunk
                    $targetChannelsQuery = $targetPlaylist->channels()->select($targetFieldsToSelect);

                    // Apply the match conditions
                    foreach ($this->channelMatchAttributes as $attribute) {
                        if (isset($matchConditions[$attribute]) && ! empty($matchConditions[$attribute])) {
                            $targetChannelsQuery->whereIn($attribute, $matchConditions[$attribute]);
                        }
                    }

                    // Build lookup of existing target channels by match key
                    $targetChannelsByMatchKey = [];
                    foreach ($targetChannelsQuery->cursor() as $targetChannel) {
                        $matchKey = $this->buildMatchKey($targetChannel, $this->channelMatchAttributes);
                        if ($matchKey !== null) {
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
                            $updateData = $this->buildUpdateData($sourceChannel, $targetChannel, $attributeMapping, $groupNameToId, $targetPlaylist);

                            if (! empty($updateData)) {
                                $updateData['updated_at'] = now();
                                $updates[$targetChannel->id] = $updateData;
                            }
                        } else {
                            // Create new channel
                            $channelData = $this->buildChannelData($sourceChannel, $targetPlaylist, $groupNameToId);
                            $channelsToCreate[] = $channelData;
                        }
                    }

                    // Batch update existing channels
                    if (! empty($updates)) {
                        foreach ($updates as $channelId => $updateData) {
                            Channel::query()->where('id', $channelId)->update($updateData);
                            $totalUpdated++;
                        }
                    }

                    // Batch insert new channels using Eloquent to ensure casts are applied
                    if (! empty($channelsToCreate)) {
                        foreach ($channelsToCreate as $channelData) {
                            Channel::create($channelData);
                        }
                        $totalCreated += count($channelsToCreate);
                    }
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
                ->chunkById($batchSize, function ($targetChannels) use ($sourcePlaylist, $sourceFieldsToSelect, $attributeMapping, &$totalUpdated, &$groupNameToId, $targetPlaylist) {
                    $updates = [];

                    // Build WHERE conditions to find matching source channels
                    $matchConditions = $this->buildMatchConditions($targetChannels, $this->channelMatchAttributes);

                    if (empty($matchConditions)) {
                        return;
                    }

                    // Query only the source channels that could potentially match this target chunk
                    $sourceChannelsQuery = $sourcePlaylist->channels()->select($sourceFieldsToSelect);

                    foreach ($this->channelMatchAttributes as $attribute) {
                        if (isset($matchConditions[$attribute]) && ! empty($matchConditions[$attribute])) {
                            $sourceChannelsQuery->whereIn($attribute, $matchConditions[$attribute]);
                        }
                    }

                    // Build lookup of source channels by match key
                    $sourceChannelsByMatchKey = [];
                    foreach ($sourceChannelsQuery->cursor() as $sourceChannel) {
                        $matchKey = $this->buildMatchKey($sourceChannel, $this->channelMatchAttributes);
                        if ($matchKey !== null) {
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
                        $updateData = $this->buildUpdateData($sourceChannel, $targetChannel, $attributeMapping, $groupNameToId, $targetPlaylist);

                        if (! empty($updateData)) {
                            $updateData['updated_at'] = now();
                            $updates[$targetChannel->id] = $updateData;
                        }
                    }

                    // Batch update all channels in this chunk
                    if (! empty($updates)) {
                        foreach ($updates as $channelId => $updateData) {
                            DB::table('channels')
                                ->where('id', $channelId)
                                ->update($updateData);
                            $totalUpdated++;
                        }
                    }
                });
        }

        $totalProcessed = $totalUpdated + $totalCreated;
        Log::info("CopyAttributesToPlaylist: Updated {$totalUpdated} and created {$totalCreated} channels from playlist {$sourcePlaylist->id} to playlist {$targetPlaylist->id}");

        return $totalProcessed;
    }

    /**
     * Build update data array for an existing target channel from source channel
     */
    private function buildUpdateData(
        $sourceChannel,
        $targetChannel,
        array $attributeMapping,
        array &$groupNameToId,
        Playlist $targetPlaylist
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

                if (array_key_exists($lower, $groupNameToId)) {
                    $groupId = $groupNameToId[$lower];
                } else {
                    // Create the group for the target playlist and cache the id
                    $customGroup = Group::query()->create([
                        'name' => $desiredName,
                        'playlist_id' => $targetPlaylist->id,
                        'user_id' => $targetPlaylist->user_id ?? null,
                        'sort_order' => 0,
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
        array &$groupNameToId
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

            if (array_key_exists($lower, $groupNameToId)) {
                $channelData['group_id'] = $groupNameToId[$lower];
            } else {
                // Create the group and cache it
                $customGroup = Group::query()->create([
                    'name' => $groupName,
                    'playlist_id' => $targetPlaylist->id,
                    'user_id' => $targetPlaylist->user_id ?? null,
                    'sort_order' => 0,
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
     * @param  \Illuminate\Support\Collection  $targetChannels
     * @return array<string, array<string>> Map of attribute => unique values
     */
    private function buildMatchConditions($targetChannels, array $matchAttributes): array
    {
        $conditions = [];

        foreach ($matchAttributes as $attribute) {
            $values = [];
            foreach ($targetChannels as $channel) {
                $value = $channel->{$attribute} ?? null;
                if ($value !== null && $value !== '') {
                    // Store normalized value for matching
                    $values[] = $value;
                }
            }

            // Store unique values for this attribute
            if (! empty($values)) {
                $conditions[$attribute] = array_unique($values);
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
                // Handle special cases first
                case 'logo':
                    $mapping['logo_internal'] = 'logo'; // Special case: source logo_internal -> custom logo
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
