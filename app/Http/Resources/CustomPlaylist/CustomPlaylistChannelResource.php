<?php

namespace App\Http\Resources\CustomPlaylist;

use App\Models\Channel;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A channel attached to a Custom Playlist, with its per-playlist pivot values.
 *
 * Expects the channel to be loaded through the custom playlist's `channels` relation
 * (for the pivot) with its `tags` constrained to the playlist's group tag type.
 *
 * @mixin Channel
 */
#[SchemaName('CustomPlaylistChannel')]
class CustomPlaylistChannelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** @var string|null */
            'title' => $this->title_custom ?: $this->title,
            'enabled' => (bool) $this->enabled,
            'is_vod' => (bool) $this->is_vod,
            /**
             * The channel's custom group tag in this playlist, if any.
             *
             * @var string|null
             */
            'group' => $this->tags->first()?->getAttributeValue('name'),
            /**
             * Per-playlist channel number.
             *
             * @var int|null
             */
            'channel_number' => $this->pivot->channel_number,
            /**
             * Per-playlist sort order.
             *
             * @var int|float|null
             */
            'sort' => $this->pivot->sort,
        ];
    }
}
