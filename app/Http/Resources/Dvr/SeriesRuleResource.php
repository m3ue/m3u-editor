<?php

namespace App\Http\Resources\Dvr;

use App\Enums\DvrMatchMode;
use App\Enums\DvrSeriesMode;
use App\Models\DvrRecordingRule;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A series rule in Dispatcharr's shape. Null fields are omitted, like Dispatcharr does.
 *
 * @mixin DvrRecordingRule
 */
#[SchemaName('SeriesRule')]
class SeriesRuleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** EPG channel id of the pinned channel, or an empty string when the rule matches any channel. */
            'tvg_id' => $this->channel?->epgChannel?->channel_id ?? '',
            /** @var 'all'|'new' */
            'mode' => $this->series_mode === DvrSeriesMode::NewFlag ? 'new' : 'all',
            /** @var string */
            'title' => $this->whenNotNull($this->series_title),
            /** @var 'exact'|'contains' */
            'title_mode' => $this->match_mode === DvrMatchMode::Exact ? 'exact' : 'contains',
            /** Always empty. Description matching is not supported. */
            'description' => '',
            'description_mode' => 'contains',
            /** Channel the rule is pinned to. Omitted when the rule matches any channel. */
            'channel_id' => $this->whenNotNull($this->channel_id),
            'enabled' => (bool) $this->enabled,
        ];
    }
}
