<?php

namespace Database\Factories;

use App\Enums\EpgMapCandidateStatus;
use App\Models\Channel;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\EpgMapCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;

class EpgMapCandidateFactory extends Factory
{
    protected $model = EpgMapCandidate::class;

    public function definition(): array
    {
        return [
            'epg_map_id' => EpgMap::factory(),
            'channel_id' => Channel::factory(),
            'epg_channel_id' => EpgChannel::factory(),
            'original_name' => fake()->name(),
            'normalized_name' => fake()->word(),
            'top_confidence' => fake()->numberBetween(0, 100),
            'top_reason' => fake()->word(),
            'top_matched_value' => fake()->word(),
            'top_normalized_value' => fake()->word(),
            'is_exact' => fake()->boolean(),
            'automatic_match' => fake()->boolean(),
            'alternatives' => null,
            'status' => EpgMapCandidateStatus::Pending,
            'applied_at' => null,
        ];
    }
}
