<?php

namespace App\Http\Resources\Group;

use App\Models\Group;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single group with its channel counts.
 *
 * Expects the `playlist` relation and the channel counts from
 * GroupController::groupCountRelations() to be loaded.
 *
 * @mixin Group
 */
#[SchemaName('Group')]
class GroupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** The group name, including any custom rename. */
            'name' => $this->name_internal ?? $this->name,
            /**
             * Sort position. A decimal column, so some database drivers return it as a string.
             *
             * @var string|int|float|null
             */
            'sort_order' => $this->sort_order,
            /** @var 'live'|'vod' */
            'type' => $this->type ?? 'live',
            'enabled' => (bool) $this->enabled,
            /** True for groups created manually (including through the API) rather than by a playlist sync. */
            'custom' => (bool) $this->custom,
            /** @var int */
            'total_channels' => $this->channels_count ?? 0,
            /** @var int */
            'enabled_channels' => $this->enabled_channels_count ?? 0,
            /** @var int */
            'live_channels' => $this->live_channels_count ?? 0,
            /** @var int */
            'vod_channels' => $this->vod_channels_count ?? 0,
            'playlist' => $this->playlist ? [
                'id' => $this->playlist->id,
                'name' => $this->playlist->name,
                'uuid' => $this->playlist->uuid,
            ] : null,
        ];
    }
}
