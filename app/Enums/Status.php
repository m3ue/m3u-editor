<?php

namespace App\Enums;

enum Status: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Processing => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
