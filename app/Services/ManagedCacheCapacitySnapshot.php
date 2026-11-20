<?php

namespace App\Services;

final readonly class ManagedCacheCapacitySnapshot
{
    public function __construct(
        public string $group,
        public ?int $limit,
        public ?int $live,
        public ?int $background,
    ) {}
}
