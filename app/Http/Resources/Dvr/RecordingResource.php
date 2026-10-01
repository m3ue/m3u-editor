<?php

namespace App\Http\Resources\Dvr;

use App\Enums\DvrRecordingStatus;
use App\Enums\ImageProfile;
use App\Http\Controllers\XtreamApiController;
use App\Models\DvrRecording;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recording in Dispatcharr's RecordingSerializer shape: a thin row with
 * everything descriptive nested in `custom_properties`.
 *
 * @mixin DvrRecording
 */
#[SchemaName('Recording')]
class RecordingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $programmeData = $this->epg_programme_data ?? [];
        $isCompleted = $this->status === DvrRecordingStatus::Completed;
        $isLive = $this->status === DvrRecordingStatus::Recording && $this->proxy_network_id;

        $fileUrl = match (true) {
            $isLive => route('dispatcharr.dvr.recordings.hls', ['id' => $this->id, 'path' => 'index.m3u8'], false),
            $isCompleted && $this->hasFilePath() => self::filePath($this->resource),
            default => null,
        };

        return [
            'id' => $this->id,
            /** Channel id the recording is on. */
            'channel' => $this->channel_id,
            /** @format date-time */
            'start_time' => $this->scheduled_start?->toIso8601String(),
            /** @format date-time */
            'end_time' => $this->scheduled_end?->toIso8601String(),
            /** Always null. Kept for Dispatcharr compatibility. */
            'task_id' => null,
            'custom_properties' => [
                /** @var 'scheduled'|'recording'|'completed'|'stopped'|'interrupted' */
                'status' => self::dispatcharrStatus($this->resource),
                'program' => [
                    /** Always null. Kept for Dispatcharr compatibility. */
                    'id' => null,
                    /** @var string|null */
                    'tvg_id' => $programmeData['epg_channel_id'] ?? $this->channel?->epgChannel?->channel_id,
                    'title' => $this->title,
                    'sub_title' => $this->subtitle,
                    'description' => $this->description,
                    /** @format date-time */
                    'start_time' => ($this->programme_start ?? $this->scheduled_start)?->toIso8601String(),
                    /** @format date-time */
                    'end_time' => ($this->programme_end ?? $this->scheduled_end)?->toIso8601String(),
                    'season' => $this->season,
                    'episode' => $this->episode,
                ],
                'season' => $this->season,
                'episode' => $this->episode,
                /** @var string|null */
                'rating' => $programmeData['rating'] ?? null,
                /** Best available artwork, routed through the logo proxy when the owning playlist has it enabled. */
                'poster_url' => $this->posterUrl(),
                /** Live HLS playlist while recording, the file endpoint once completed, otherwise null. */
                'file_url' => $fileUrl,
                /** The file endpoint once completed, otherwise null. */
                'output_file_url' => $isCompleted ? $fileUrl : null,
                'file_name' => $this->file_path ? basename($this->file_path) : null,
                'bytes_written' => $this->file_size_bytes,
                /**
                 * @var string|null
                 *
                 * @format date-time
                 */
                'started_at' => $this->actual_start?->toIso8601String(),
                /**
                 * @var string|null
                 *
                 * @format date-time
                 */
                'ended_at' => $this->actual_end?->toIso8601String(),
                /** Failure reason when the status is `interrupted`. */
                'interrupted_reason' => $this->status === DvrRecordingStatus::Failed ? $this->error_message : null,
                'uuid' => $this->uuid,
            ],
        ];
    }

    /**
     * Map a native status onto Dispatcharr's vocabulary
     * (scheduled / recording / completed / stopped / interrupted).
     */
    public static function dispatcharrStatus(DvrRecording $recording): string
    {
        return match ($recording->status) {
            DvrRecordingStatus::Scheduled => 'scheduled',
            DvrRecordingStatus::Recording, DvrRecordingStatus::PostProcessing => 'recording',
            DvrRecordingStatus::Completed, DvrRecordingStatus::Purged => $recording->user_cancelled ? 'stopped' : 'completed',
            DvrRecordingStatus::Cancelled => 'stopped',
            DvrRecordingStatus::Failed => 'interrupted',
        };
    }

    /**
     * Relative URL of the endpoint that streams the finished recording file.
     */
    public static function filePath(DvrRecording $recording): string
    {
        return route('dispatcharr.dvr.recordings.file', ['id' => $recording->id], false).'/';
    }

    /**
     * Best available artwork, routed through the logo proxy when the playlist that
     * owns the recording's DVR setting has it enabled (same as the Xtream API).
     */
    private function posterUrl(): ?string
    {
        $metadata = $this->metadata ?? [];
        $posterUrl = $metadata['tmdb']['poster_url']
            ?? $metadata['tvmaze']['poster_url']
            ?? $this->epg_programme_icon
            ?? $this->channel_icon;

        if (! $this->dvrSetting?->owner()?->enable_logo_proxy) {
            return $posterUrl;
        }

        return XtreamApiController::proxyImageUrl($posterUrl, ImageProfile::Poster);
    }
}
