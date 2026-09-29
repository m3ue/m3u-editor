<?php

namespace App\Http\Resources\Channel;

use App\Enums\ChannelLogoType;
use App\Models\Channel;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A single channel with its EPG mapping, failovers, metadata and the original provider
 * values alongside any custom overrides.
 *
 * Expects the `playlist`, `customPlaylist`, `group`, `epgChannel` and `failoverChannels`
 * relations to be loaded.
 *
 * @mixin Channel
 */
#[SchemaName('ChannelDetail')]
class ChannelDetailResource extends ChannelResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** @var string|null */
            'title' => $this->title_custom ?? $this->title,
            /** @var string|null */
            'title_original' => $this->title,
            /** @var string|null */
            'name' => $this->name_custom ?? $this->name,
            /** @var string|null */
            'name_original' => $this->name,
            /** @var string|null */
            'logo' => $this->logo ?? $this->logo_internal,
            /** @var string|null */
            'logo_internal' => $this->logo_internal,
            /** @var string|null */
            'url' => $this->url_custom ?? $this->url,
            /** @var string|null */
            'url_original' => $this->url,
            /** @var string|null */
            'stream_id' => $this->stream_id_custom ?? $this->stream_id,
            /** @var string|null */
            'stream_id_original' => $this->stream_id,
            'enabled' => $this->enabled,
            'is_vod' => $this->is_vod,
            /** @var int|null */
            'channel_number' => $this->channel,
            'sort_order' => $this->sort,
            'catchup' => $this->catchup ?? false,
            'shift' => $this->shift ?? 0,
            'tvg_shift' => $this->tvg_shift,
            /** @var 'channel'|'epg'|null */
            'logo_type' => $this->logo_type?->value,
            'use_epg_logo' => $this->logo_type === ChannelLogoType::Epg,
            'epg_map_enabled' => $this->epg_map_enabled ?? true,
            'proxy_url' => $this->getProxyUrl(),
            /** The mapped EPG channel, or null when unmapped. */
            'epg' => $this->epgChannel ? [
                /** EPG channel record id. */
                'channel_id' => $this->epgChannel->id,
                /**
                 * The tvg-id from the EPG source.
                 *
                 * @var string
                 */
                'epg_id' => $this->epgChannel->channel_id,
                /** @var string|null */
                'name' => $this->epgChannel->name,
            ] : null,
            /** @var int|null */
            'epg_channel_id' => $this->epg_channel_id,
            /**
             * The group title stored on the channel.
             *
             * @var string|null
             */
            'group_title' => $this->group,
            'group' => $this->groupSummary(),
            'playlist' => $this->playlistSummary(),
            'failovers' => ChannelFailoverResource::collection($this->failoverChannels),
            'metadata' => [
                'year' => $this->year,
                'rating' => $this->rating,
                'rating_5based' => $this->rating_5based,
                /** @var bool */
                'has_info' => $this->has_metadata,
            ],
            /** @format date-time */
            'created_at' => $this->created_at?->toIso8601String(),
            /** @format date-time */
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
