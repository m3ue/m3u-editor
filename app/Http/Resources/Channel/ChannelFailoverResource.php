<?php

namespace App\Http\Resources\Channel;

use App\Models\Channel;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A failover channel attached to a primary channel, loaded through the
 * `failoverChannels` relation.
 *
 * @mixin Channel
 */
#[SchemaName('ChannelFailover')]
class ChannelFailoverResource extends JsonResource
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
            /**
             * Failover order, lowest first.
             *
             * @var int
             */
            'priority' => $this->pivot->sort ?? 0,
        ];
    }
}
