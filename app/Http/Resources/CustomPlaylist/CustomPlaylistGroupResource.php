<?php

namespace App\Http\Resources\CustomPlaylist;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Tags\Tag;

/**
 * A Custom Playlist group, stored as a tag scoped to the playlist.
 *
 * @mixin Tag
 */
#[SchemaName('CustomPlaylistGroup')]
class CustomPlaylistGroupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** @var string */
            'name' => $this->getAttributeValue('name'),
            /**
             * Sort position, matching the UI's drag-to-reorder.
             *
             * @var int|null
             */
            'order_column' => $this->order_column,
        ];
    }
}
