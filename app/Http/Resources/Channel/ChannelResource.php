<?php

namespace App\Http\Resources\Channel;

use App\Models\Channel;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A channel as returned by the channel list. Custom values (e.g. `title_custom`) take
 * precedence over the provider values.
 *
 * Expects the `playlist`, `customPlaylist` and `group` relations to be loaded.
 *
 * @mixin Channel
 */
#[SchemaName('Channel')]
class ChannelResource extends JsonResource
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
            'name' => $this->name_custom ?? $this->name,
            /** @var string|null */
            'logo' => $this->logo ?? $this->logo_internal,
            /** @var string|null */
            'url' => $this->url_custom ?? $this->url,
            /** @var string|null */
            'stream_id' => $this->stream_id_custom ?? $this->stream_id,
            'enabled' => $this->enabled,
            'is_vod' => $this->is_vod,
            /** @var int|null */
            'channel_number' => $this->channel,
            /** The channel's group, or null when it has none. */
            'group' => $this->groupSummary(),
            'proxy_url' => $this->getProxyUrl(),
            /** The playlist (or custom playlist) the channel belongs to. */
            'playlist' => $this->playlistSummary(),
        ];
    }

    /**
     * @return array{id: int, name: string, uuid: string, proxy_enabled: bool}|null
     */
    protected function playlistSummary(): ?array
    {
        $playlist = $this->getEffectivePlaylist();

        if (! $playlist) {
            return null;
        }

        return [
            'id' => $playlist->id,
            'name' => $playlist->name,
            'uuid' => $playlist->uuid,
            'proxy_enabled' => (bool) ($playlist->enable_proxy ?? false),
        ];
    }

    /**
     * Uses the loaded relation rather than the attribute, since `group` is also a column.
     *
     * @return array{id: int, name: string}|null
     */
    protected function groupSummary(): ?array
    {
        if (! $this->relationLoaded('group') || ! $this->getRelation('group')) {
            return null;
        }

        return [
            'id' => $this->getRelation('group')->id,
            'name' => $this->getRelation('group')->name,
        ];
    }
}
