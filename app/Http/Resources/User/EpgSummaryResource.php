<?php

namespace App\Http\Resources\User;

use App\Models\Epg;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An EPG with its channel count and sync status.
 *
 * Expects the `channels` count to be loaded.
 *
 * @mixin Epg
 */
#[SchemaName('EpgSummary')]
class EpgSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'uuid' => $this->uuid,
            /** @var int */
            'channel_count' => $this->channels_count,
            /**
             * @var string|null
             *
             * @format date-time
             */
            'last_sync' => $this->synced?->toIso8601String(),
            /** @var string */
            'status' => $this->status?->value ?? 'Unknown',
            /** @var string */
            'source_type' => $this->source_type?->value ?? 'xmltv',
            'is_processing' => (bool) $this->processing,
        ];
    }
}
