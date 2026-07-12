<?php

namespace App\Enums;

enum EpgMapCandidateStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Skipped = 'skipped';
    case Stale = 'stale';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Applied => __('Applied'),
            self::Skipped => __('Skipped'),
            self::Stale => __('Stale'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Applied => 'success',
            self::Skipped => 'warning',
            self::Stale => 'danger',
        };
    }
}
