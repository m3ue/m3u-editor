<?php

namespace App\Enums;

enum DvrRecordingStatus: string
{
    case Scheduled = 'scheduled';
    case Recording = 'recording';
    case PostProcessing = 'post_processing';
    case Completed = 'completed';
    case Purged = 'purged';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Scheduled => __('Scheduled'),
            self::Recording => __('Recording'),
            self::PostProcessing => __('Post Processing'),
            self::Completed => __('Completed'),
            self::Purged => __('Purged'),
            self::Failed => __('Failed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Scheduled => 'info',
            self::Recording => 'warning',
            self::PostProcessing => 'warning',
            self::Completed => 'success',
            self::Purged => 'gray',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Scheduled => 'heroicon-o-clock',
            self::Recording => 'heroicon-o-signal',
            self::PostProcessing => 'heroicon-o-cog-6-tooth',
            self::Completed => 'heroicon-o-check-circle',
            self::Purged => 'heroicon-o-archive-box-x-mark',
            self::Failed => 'heroicon-o-x-circle',
            self::Cancelled => 'heroicon-o-minus-circle',
        };
    }
}
