<?php

namespace App\Enums;

enum CachedContentFileStatus: string
{
    case Pending = 'pending';
    case Downloading = 'downloading';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Downloading => __('Downloading'),
            self::Completed => __('Completed'),
            self::Failed => __('Failed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Downloading => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::Downloading => 'heroicon-o-arrow-down-tray',
            self::Completed => 'heroicon-o-check-circle',
            self::Failed => 'heroicon-o-x-circle',
        };
    }
}
