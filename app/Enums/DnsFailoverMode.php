<?php

namespace App\Enums;

enum DnsFailoverMode: string
{
    case Static = 'static';
    case Inherit = 'inherit';
    case Independent = 'independent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Static => __('Static (no failover)'),
            self::Inherit => __('Inherit from source playlist'),
            self::Independent => __('Independent failover URLs'),
        };
    }
}
