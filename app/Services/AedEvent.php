<?php

namespace App\Services;

use Carbon\Carbon;

class AedEvent
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?Carbon $start,
        public readonly ?Carbon $end,
        public readonly int $durationMinutes,
    ) {}

    public function hasTime(): bool
    {
        return $this->start !== null && $this->end !== null;
    }
}
