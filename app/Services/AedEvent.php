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
        /** Programme icon, when the event brings its own (a league crest); else the profile's. */
        public readonly ?string $icon = null,
        /** EPG category, when the event brings its own; else the profile's. */
        public readonly ?string $category = null,
    ) {}

    public function hasTime(): bool
    {
        return $this->start !== null && $this->end !== null;
    }
}
