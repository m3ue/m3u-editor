<?php

namespace App\Http\Resources\Dvr;

use App\Models\EpgProgramme;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An upcoming airing a series rule would match, returned by the rule preview.
 *
 * @mixin EpgProgramme
 */
#[SchemaName('SeriesRuleMatch')]
class SeriesRuleMatchResource extends JsonResource
{
    /**
     * Needs `willRecord`, so `::collection()` and `::make()` can't build it; map the
     * programmes into instances instead (see DispatcharrDvrController::previewSeriesRule()).
     */
    public function __construct(EpgProgramme $programme, private readonly bool $willRecord)
    {
        parent::__construct($programme);
    }

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** @var string */
            'tvg_id' => $this->epg_channel_id,
            /** @var string */
            'title' => $this->title,
            /** @var string|null */
            'sub_title' => $this->subtitle,
            /** @var string|null */
            'description' => $this->description,
            /** @format date-time */
            'start_time' => $this->start_time?->toIso8601String(),
            /** @format date-time */
            'end_time' => $this->end_time?->toIso8601String(),
            /** @var int|null */
            'season' => $this->season,
            /** @var int|null */
            'episode' => $this->episode,
            'is_new' => (bool) $this->is_new,
            /** False when the rule would skip this airing because the episode is already recorded or scheduled. */
            'will_record' => $this->willRecord,
        ];
    }
}
