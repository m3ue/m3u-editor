<?php

namespace App\Enums;

enum EpgSourceType: string
{
    case URL = 'url';
    case SCHEDULES_DIRECT = 'schedules_direct';

    public function label(): string
    {
        return match ($this) {
            self::URL => 'URL/XML File',
            self::SCHEDULES_DIRECT => 'SchedulesDirect',
        };
    }
}
