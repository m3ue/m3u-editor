<?php

namespace App\Http\Resources\Group;

use App\Models\Group;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A group as returned by the group list. Channel counts are only included when
 * requested (`with_channels`), and `playlist` is omitted when the group has none.
 *
 * @mixin Group
 */
#[SchemaName('GroupSummary')]
class GroupSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /**
             * Sort position. A decimal column, so some database drivers return it as a string.
             *
             * @var string|int|float|null
             */
            'sort_order' => $this->sort_order,
            /** @var 'live'|'vod' */
            'type' => $this->type ?? 'live',
            'total_channels' => $this->whenCounted('channels'),
            'enabled_channels' => $this->whenCounted('enabled_channels'),
            'playlist' => $this->when((bool) $this->playlist, fn () => [
                'id' => $this->playlist->id,
                'name' => $this->playlist->name,
                'uuid' => $this->playlist->uuid,
            ]),
        ];
    }
}
